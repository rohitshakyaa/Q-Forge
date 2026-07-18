<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use App\Services\PaperGeneration\Contracts\SimilarityGuard;
use App\Services\Rag\QdrantClient;
use App\Services\Rag\VectorMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Embedding-based within-paper duplicate guard (builds on the RAG stack —
 * docs/RAG-GUIDE.md). Questions are already vectorised in Qdrant by their row
 * id; this guard pulls the vectors for the candidates in play and compares them
 * with the same `duplicate_threshold` used everywhere else, so "Define a binary
 * tree" and "What is a binary tree?" are recognised as the same question even
 * though their rows (and ids) differ.
 *
 * Fail-open by design (like {@see \App\Services\Rag\DuplicateDetector}): if RAG
 * is disabled or Qdrant is unreachable it degrades to "nothing is similar" and
 * generation proceeds on id-uniqueness alone. A missing vector for either side
 * of a comparison is treated the same way — never a false "duplicate".
 *
 * Resolve a fresh instance per generation run: the vector cache is per-paper
 * state, so this must not be a singleton.
 */
final class VectorSimilarityGuard implements SimilarityGuard
{
    /** @var array<int, array<int, float>> questionId => vector, loaded on demand */
    private array $vectors = [];

    /** @var array<int, true> ids we've already tried to load (hit or miss) */
    private array $known = [];

    private bool $broken = false;

    public function __construct(private readonly QdrantClient $qdrant)
    {
        // RAG off ⇒ permanently degraded: behave exactly like NullSimilarityGuard.
        $this->broken = ! config('services.qdrant.enabled');
    }

    public function prime(Collection $questions): void
    {
        if ($this->broken) {
            return;
        }

        $missing = $questions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => isset($this->known[$id]))
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        // Mark every requested id as attempted up front, so ids Qdrant has no
        // vector for aren't re-requested on the next slot.
        foreach ($missing as $id) {
            $this->known[$id] = true;
        }

        try {
            $found = $this->qdrant->retrieve(QdrantClient::COLLECTION_QUESTIONS, $missing->all());
            foreach ($found as $id => $vector) {
                $this->vectors[(int) $id] = $vector;
            }
        } catch (\Throwable $e) {
            // One failure degrades the whole run; don't retry per slot.
            $this->broken = true;
            Log::warning('VectorSimilarityGuard: vector lookup failed, within-paper dedup disabled for this run', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function tooSimilar(Question $candidate, array $chosen): bool
    {
        if ($this->broken || $chosen === []) {
            return false;
        }

        $candidateVector = $this->vectors[(int) $candidate->id] ?? null;
        if ($candidateVector === null) {
            return false; // Unknown signal — fail open, never block.
        }

        $threshold = (float) config('services.qdrant.duplicate_threshold');

        foreach ($chosen as $question) {
            $vector = $this->vectors[(int) $question->id] ?? null;
            if ($vector === null) {
                continue;
            }

            if (VectorMath::cosine($candidateVector, $vector) >= $threshold) {
                return true;
            }
        }

        return false;
    }
}
