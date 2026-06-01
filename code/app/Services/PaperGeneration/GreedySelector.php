<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Picks the best candidate for a slot by a least-recently-used policy: lowest
 * `used_count` first, ties broken by id (deterministic).
 *
 * Deliberately unit-agnostic. Spreading questions across units / unit coverage
 * is treated as a validated constraint, not baked into the greedy step — which
 * is precisely what lets a naive greedy pass starve a unit and what the
 * BacktrackingResolver then repairs. That contrast is the academic point.
 *
 * @param  Collection<int, Question>  $candidates
 */
class GreedySelector
{
    public function pick(Collection $candidates): ?Question
    {
        return $candidates
            ->sort(function (Question $a, Question $b) {
                return [$a->used_count, $a->id] <=> [$b->used_count, $b->id];
            })
            ->first();
    }
}
