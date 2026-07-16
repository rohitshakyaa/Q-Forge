<?php

namespace App\Services\Rag;

use App\Models\Question;
use App\Services\PythonService;
use Illuminate\Support\Facades\Log;

/**
 * Annotates freshly-extracted candidates with RAG-derived review hints
 * (M6 Phases 1 & 3 — docs/RAG-GUIDE.md). Two annotations, one embedding pass:
 *
 *  - `attributes.similar` (Phase 1): the nearest approved lookalike, when one
 *    clears the duplicate threshold — "similar to Q#123 (0.93)".
 *  - `attributes.suggested_units` (Phase 3): ranked unit suggestions, from
 *    searching the candidate's vector against the unit-content chunk index and
 *    aggregating the best score per unit.
 *
 * Both are flag-only by design. Unlike the AI expansion (an automated pipeline
 * that over-asks, where dropping is cheap), these candidates came from a real
 * exam paper a human chose to upload — so the reviewer sees the hints on the
 * card and decides. Similarity has false positives ("Define TCP" vs "Define
 * UDP" score high) and unit tags are human-assigned by project decision
 * (Post-M5): suggestions pre-fill, never auto-assign.
 *
 * Runs once at extraction time; the review queue renders the stored verdicts
 * with zero extra lookups. Snapshots, not live views — `checked_at` says when.
 */
class SimilarQuestionFinder
{
    /** How many chunk hits to aggregate per candidate before ranking units. */
    private const CHUNK_POOL = 8;

    /** At most this many unit suggestions per candidate. */
    private const MAX_SUGGESTIONS = 3;

    public function __construct(
        private readonly PythonService $python,
        private readonly QdrantClient $qdrant,
    ) {}

    /**
     * Batch-annotate pending candidates: one /embed call for the lot, then per
     * candidate one search against the approved bank (similar) and one against
     * the chunk index (unit suggestions). Fails open — extraction must not
     * fail because RAG is down.
     *
     * @param  iterable<int, Question>  $questions
     * @return int  how many candidates received at least one annotation
     */
    public function annotate(iterable $questions): int
    {
        if (! config('services.qdrant.enabled')) {
            return 0;
        }

        $questions = collect($questions)->values();
        if ($questions->isEmpty()) {
            return 0;
        }

        try {
            $vectors = $this->python->embed($questions->map(fn (Question $q) => (string) $q->text)->all())['embeddings'];

            $annotated = 0;
            foreach ($questions as $i => $question) {
                $hints = array_filter([
                    'similar' => $this->nearestLookalike($question, $vectors[$i]),
                    'suggested_units' => $this->suggestUnits($question, $vectors[$i]),
                ]);

                if ($hints === []) {
                    continue;
                }

                $question->update([
                    'attributes' => array_merge($question->attributes ?? [], $hints, [
                        'rag_checked_at' => now()->toIso8601String(),
                    ]),
                ]);
                $annotated++;
            }

            return $annotated;
        } catch (\Throwable $e) {
            Log::warning('SimilarQuestionFinder: annotation skipped, RAG unavailable', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Phase 1: the nearest approved same-subject question at/above the
     * duplicate threshold, or null when the candidate is fresh.
     *
     * @param  array<int, float>  $vector
     * @return array{question_id: int|string, score: float}|null
     */
    private function nearestLookalike(Question $question, array $vector): ?array
    {
        $hits = $this->qdrant->search(
            QdrantClient::COLLECTION_QUESTIONS,
            $vector,
            limit: 1,
            filter: ['must' => [['key' => 'subject_id', 'match' => ['value' => (int) $question->subject_id]]]],
        );

        if ($hits === [] || $hits[0]['score'] < (float) config('services.qdrant.duplicate_threshold')) {
            return null;
        }

        return [
            'question_id' => $hits[0]['id'],
            'score' => round($hits[0]['score'], 4),
        ];
    }

    /**
     * Phase 3: ranked unit suggestions — search the chunk index, keep each
     * unit's best-scoring chunk, rank. A unit with no indexed content can never
     * be suggested (expected: suggestions are a convenience, the dropdown still
     * lists every unit). Subject-level syllabus chunks (unit_id null) are
     * skipped — they describe the course, not one unit.
     *
     * @param  array<int, float>  $vector
     * @return array<int, array{unit_id: int, score: float}>|null  best-first; null when nothing clears the floor
     */
    private function suggestUnits(Question $question, array $vector): ?array
    {
        $hits = $this->qdrant->search(
            QdrantClient::COLLECTION_CHUNKS,
            $vector,
            limit: self::CHUNK_POOL,
            filter: ['must' => [['key' => 'subject_id', 'match' => ['value' => (int) $question->subject_id]]]],
        );

        $floor = (float) config('services.qdrant.unit_suggestion_min_score');

        $best = [];
        foreach ($hits as $hit) {
            $unitId = $hit['payload']['unit_id'] ?? null;
            if ($unitId === null || $hit['score'] < $floor) {
                continue;
            }
            $best[(int) $unitId] = max($best[(int) $unitId] ?? 0.0, $hit['score']);
        }

        if ($best === []) {
            return null;
        }

        arsort($best);

        $suggestions = [];
        foreach (array_slice($best, 0, self::MAX_SUGGESTIONS, preserve_keys: true) as $unitId => $score) {
            $suggestions[] = ['unit_id' => $unitId, 'score' => round($score, 4)];
        }

        return $suggestions;
    }
}
