<?php

namespace App\Services\PaperGeneration\Contracts;

use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Guards a single paper against near-duplicate questions — two questions that
 * *mean* the same thing (not just the same row), which id-only de-duplication
 * cannot catch. Used by the greedy pass to prefer dissimilar candidates.
 *
 * Contract: the engine calls {@see prime()} with each candidate pool before it
 * asks {@see tooSimilar()}, so an implementation can batch-load whatever it
 * needs. Every implementation must fail *open* — when the signal is unavailable
 * (RAG disabled, index down) it must report "not similar" so generation never
 * breaks or blocks over a soft preference.
 */
interface SimilarityGuard
{
    /**
     * Make sure the guard can answer for every question in this pool (e.g. load
     * their vectors). Cheap to call repeatedly — already-known questions are
     * skipped. Must never throw.
     *
     * @param  Collection<int, Question>  $questions
     */
    public function prime(Collection $questions): void;

    /**
     * True when $candidate is a near-duplicate of any already-chosen question.
     * False when it is fresh, when either side's signal is unknown, or when the
     * guard is disabled/degraded.
     *
     * @param  array<int, Question>  $chosen  questions already placed in this paper
     */
    public function tooSimilar(Question $candidate, array $chosen): bool;
}
