<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use App\Services\PaperGeneration\Support\CompiledBlueprint;
use App\Services\PaperGeneration\Support\TieBreaker;
use Illuminate\Support\Collection;

/**
 * When the greedy pass produces an invalid paper (typically a unit-coverage
 * violation, since the greedy selector is deliberately unit-agnostic), this
 * resolver searches for a fully-valid assignment via a bounded, deterministic
 * depth-first search.
 *
 * At each slot it tries candidates ordered uncovered-unit-first (to drive
 * coverage), then by used_count, then id, pruning any candidate that would
 * exceed a per-unit maximum. A solution must fill every slot with a distinct
 * question, cover every allowed unit, and respect every cap. If no such
 * assignment exists within the iteration cap, it returns null and the caller
 * falls back to a best-effort partial result.
 */
class BacktrackingResolver
{
    private const MAX_ITERATIONS = 50000;

    private int $iterations = 0;

    /**
     * @param  array<int, Collection<int, Question>>  $candidatesBySlot  slotIndex => full candidate pool
     * @param  int|null  $seed  per-run seed for the final tie-break; null = id order
     * @return array<int, Question>|null  slotIndex => Question, or null if unresolved
     */
    public function resolve(CompiledBlueprint $blueprint, array $candidatesBySlot, ?int $seed = null): ?array
    {
        $this->iterations = 0;

        return $this->search($blueprint, $candidatesBySlot, 0, [], [], [], $seed);
    }

    /**
     * @param  array<int, Collection<int, Question>>  $candidatesBySlot
     * @param  array<int, Question>  $assigned  slotIndex => Question chosen so far
     * @param  int[]  $usedIds
     * @param  array<int, int>  $unitUse  unitId => questions counted against its cap
     * @param  int|null  $seed  per-run tie-break seed
     * @return array<int, Question>|null
     */
    private function search(
        CompiledBlueprint $blueprint,
        array $candidatesBySlot,
        int $depth,
        array $assigned,
        array $usedIds,
        array $unitUse,
        ?int $seed = null,
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
            // Prune cap-busting candidates: a question whose tagged capped unit
            // is already at its maximum can never appear in a valid assignment
            // from this node. (Same rule as PaperGenerator::bustsCap.)
            ->reject(function (Question $q) use ($blueprint, $unitUse) {
                foreach ($q->taggedUnitIds() as $unitId) {
                    if (isset($blueprint->unitCaps[$unitId])
                        && ($unitUse[$unitId] ?? 0) + 1 > $blueprint->unitCaps[$unitId]) {
                        return true;
                    }
                }

                return false;
            })
            ->sort(function (Question $a, Question $b) use ($uncovered, $seed) {
                // A multi-unit question ranks uncovered-first when ANY of its
                // tagged units is still uncovered.
                $aRank = array_intersect($a->taggedUnitIds(), $uncovered) !== [] ? 0 : 1;
                $bRank = array_intersect($b->taggedUnitIds(), $uncovered) !== [] ? 0 : 1;

                return [$aRank, $a->used_count, TieBreaker::key($seed, (int) $a->id)]
                    <=> [$bRank, $b->used_count, TieBreaker::key($seed, (int) $b->id)];
            })
            ->values();

        foreach ($ordered as $question) {
            $assigned[$slot->index] = $question;

            $nextUnitUse = $unitUse;
            foreach ($question->taggedUnitIds() as $unitId) {
                if (isset($blueprint->unitCaps[$unitId])) {
                    $nextUnitUse[$unitId] = ($nextUnitUse[$unitId] ?? 0) + 1;
                }
            }

            $result = $this->search(
                $blueprint,
                $candidatesBySlot,
                $depth + 1,
                $assigned,
                [...$usedIds, $question->id],
                $nextUnitUse,
                $seed,
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
            // Union semantics: a multi-unit question covers every tagged unit.
            foreach ($question->taggedUnitIds() as $unitId) {
                $ids[$unitId] = true;
            }
        }

        return array_keys($ids);
    }
}
