<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use App\Services\PaperGeneration\Support\CompiledBlueprint;
use Illuminate\Support\Collection;

/**
 * When the greedy pass produces an invalid paper (typically a unit-coverage
 * violation, since the greedy selector is deliberately unit-agnostic), this
 * resolver searches for a fully-valid assignment via a bounded, deterministic
 * depth-first search.
 *
 * At each slot it tries candidates ordered uncovered-unit-first (to drive
 * coverage), then by used_count, then id. A solution must fill every slot with
 * a distinct question and cover every allowed unit. If no such assignment
 * exists within the iteration cap, it returns null and the caller falls back to
 * a best-effort partial result.
 */
class BacktrackingResolver
{
    private const MAX_ITERATIONS = 50000;

    private int $iterations = 0;

    /**
     * @param  array<int, Collection<int, Question>>  $candidatesBySlot  slotIndex => full candidate pool
     * @return array<int, Question>|null  slotIndex => Question, or null if unresolved
     */
    public function resolve(CompiledBlueprint $blueprint, array $candidatesBySlot): ?array
    {
        $this->iterations = 0;

        return $this->search($blueprint, $candidatesBySlot, 0, [], []);
    }

    /**
     * @param  array<int, Collection<int, Question>>  $candidatesBySlot
     * @param  array<int, Question>  $assigned  slotIndex => Question chosen so far
     * @param  int[]  $usedIds
     * @return array<int, Question>|null
     */
    private function search(
        CompiledBlueprint $blueprint,
        array $candidatesBySlot,
        int $depth,
        array $assigned,
        array $usedIds,
    ): ?array {
        if (++$this->iterations > self::MAX_ITERATIONS) {
            return null;
        }

        $slots = $blueprint->slots;

        // Base case: every slot filled — accept only if unit coverage holds.
        if ($depth === count($slots)) {
            return $this->coversAllUnits($blueprint, $assigned) ? $assigned : null;
        }

        $slot = $slots[$depth];
        $pool = $candidatesBySlot[$slot->index] ?? collect();

        $covered = $this->coveredUnitIds($assigned);
        $uncovered = array_values(array_diff($blueprint->allowedUnitIds, $covered));

        $ordered = $pool
            ->reject(fn (Question $q) => in_array($q->id, $usedIds, true))
            ->sort(function (Question $a, Question $b) use ($uncovered) {
                $aRank = in_array($a->unit_id, $uncovered, true) ? 0 : 1;
                $bRank = in_array($b->unit_id, $uncovered, true) ? 0 : 1;

                return [$aRank, $a->used_count, $a->id] <=> [$bRank, $b->used_count, $b->id];
            })
            ->values();

        foreach ($ordered as $question) {
            $assigned[$slot->index] = $question;
            $result = $this->search(
                $blueprint,
                $candidatesBySlot,
                $depth + 1,
                $assigned,
                [...$usedIds, $question->id],
            );

            if ($result !== null) {
                return $result;
            }

            unset($assigned[$slot->index]);
        }

        return null;
    }

    /** @param  array<int, Question>  $assigned */
    private function coversAllUnits(CompiledBlueprint $blueprint, array $assigned): bool
    {
        if (empty($blueprint->allowedUnitIds)) {
            return true;
        }

        $covered = $this->coveredUnitIds($assigned);

        return empty(array_diff($blueprint->allowedUnitIds, $covered));
    }

    /**
     * @param  array<int, Question>  $assigned
     * @return int[]
     */
    private function coveredUnitIds(array $assigned): array
    {
        $ids = [];
        foreach ($assigned as $question) {
            $ids[$question->unit_id] = true;
        }

        return array_keys($ids);
    }
}
