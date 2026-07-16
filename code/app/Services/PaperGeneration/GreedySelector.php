<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Picks the best candidate for a slot: questions covering a still-uncovered
 * allowed unit rank first (the same coverage-first tuple the
 * BacktrackingResolver uses), then lowest `used_count` (least-recently-used
 * rotation), ties broken by id (deterministic).
 *
 * Coverage-aware but still myopic: it cannot see ahead to per-unit caps or
 * marks-shape conflicts across later slots, so an invalid greedy paper is still
 * possible — that is what the BacktrackingResolver repairs. With no uncovered
 * units (or an unrestricted blueprint) the rank degenerates to pure LRU,
 * identical to the pre-coverage behaviour.
 */
class GreedySelector
{
    /**
     * @param  Collection<int, Question>  $candidates
     * @param  int[]  $uncoveredUnitIds  allowed units no earlier pick covers
     */
    public function pick(Collection $candidates, array $uncoveredUnitIds = []): ?Question
    {
        return $candidates
            ->sort(function (Question $a, Question $b) use ($uncoveredUnitIds) {
                // A multi-unit question ranks uncovered-first when ANY of its
                // tagged units is still uncovered.
                $aRank = array_intersect($a->taggedUnitIds(), $uncoveredUnitIds) !== [] ? 0 : 1;
                $bRank = array_intersect($b->taggedUnitIds(), $uncoveredUnitIds) !== [] ? 0 : 1;

                return [$aRank, $a->used_count, $a->id] <=> [$bRank, $b->used_count, $b->id];
            })
            ->first();
    }
}
