<?php

namespace App\Services\Rag;

use App\Services\PythonService;
use Illuminate\Support\Facades\Log;

/**
 * Embedding-based near-duplicate detection (M6 Phase 1 — docs/RAG-GUIDE.md).
 *
 * Two lookalike pools, one verdict:
 *  - the indexed bank — a Qdrant top-1 search among the same subject's approved
 *    questions;
 *  - texts accepted earlier in this run — an in-memory cosine sweep, because
 *    their queued index jobs haven't run yet (a small model repeats itself
 *    within a batch more than against the bank).
 *
 * Stateful per run (`accept()` grows the in-memory pool), so resolve a fresh
 * instance per job — never a singleton. Fails open by design: if embedding or
 * Qdrant is down, `embed()` returns null once and the caller falls back to
 * text-only dedup — an expansion must not die because the index is sick.
 */
class DuplicateDetector
{
    /** @var array<int, array<int, float>> vectors accepted earlier in this run */
    private array $accepted = [];

    private bool $broken = false;

    public function __construct(
        private readonly PythonService $python,
        private readonly QdrantClient $qdrant,
    ) {}

    /**
     * Embed a batch of candidate texts — one Python round-trip for the lot.
     * Null means "no semantic dedup this round" (disabled or unavailable).
     *
     * @param  string[]  $texts
     * @return array<int, array<int, float>>|null
     */
    public function embed(array $texts): ?array
    {
        if (! config('services.qdrant.enabled') || $this->broken || $texts === []) {
            return null;
        }

        try {
            return $this->python->embed($texts)['embeddings'];
        } catch (\Throwable $e) {
            $this->broken = true; // Don't re-fail per candidate; this run degrades once.
            Log::warning('DuplicateDetector: embedding unavailable, falling back to text-only dedup', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The nearest lookalike at/above the configured threshold, or null when the
     * candidate is fresh enough to store. `id` is null when the match came from
     * this run's own accepted pool (no row id exists yet).
     *
     * @param  array<int, float>  $vector
     * @return array{id: int|string|null, score: float}|null
     */
    public function nearestDuplicate(int $subjectId, array $vector): ?array
    {
        $threshold = (float) config('services.qdrant.duplicate_threshold');

        // Pool 1: the indexed bank, same subject only (payload filter).
        if (! $this->broken) {
            try {
                $hits = $this->qdrant->search(
                    QdrantClient::COLLECTION_QUESTIONS,
                    $vector,
                    limit: 1,
                    filter: ['must' => [['key' => 'subject_id', 'match' => ['value' => $subjectId]]]],
                );

                if ($hits !== [] && $hits[0]['score'] >= $threshold) {
                    return ['id' => $hits[0]['id'], 'score' => $hits[0]['score']];
                }
            } catch (\Throwable $e) {
                $this->broken = true;
                Log::warning('DuplicateDetector: qdrant search failed, falling back to text-only dedup', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Pool 2: what this run already accepted (not yet in Qdrant).
        foreach ($this->accepted as $acceptedVector) {
            $score = VectorMath::cosine($vector, $acceptedVector);
            if ($score >= $threshold) {
                return ['id' => null, 'score' => $score];
            }
        }

        return null;
    }

    /**
     * Record a stored candidate's vector so later candidates in this run are
     * checked against it too.
     *
     * @param  array<int, float>  $vector
     */
    public function accept(array $vector): void
    {
        $this->accepted[] = $vector;
    }
}
