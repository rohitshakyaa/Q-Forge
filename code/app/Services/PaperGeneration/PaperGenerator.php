<?php

namespace App\Services\PaperGeneration;

use App\Models\Blueprint;
use App\Models\Question;
use App\Services\PaperGeneration\Support\CompiledBlueprint;
use App\Services\PaperGeneration\Support\GenerationResult;
use App\Services\PaperGeneration\Support\MissingSlot;
use App\Services\PaperGeneration\Support\Slot;
use Illuminate\Support\Collection;

/**
 * Facade orchestrating the generation engine:
 *   compile → greedy fill → validate → (on failure) backtrack → re-validate.
 *
 * Returns a GenerationResult: a fully-valid paper, or a best-effort partial
 * paper plus missing_slots naming the shortfall. Pure with respect to writes —
 * it only reads the question bank; persistence is the controller's job, which
 * keeps the engine unit-testable in isolation.
 */
class PaperGenerator
{
    public function __construct(
        private readonly BlueprintCompiler $compiler,
        private readonly CandidateFilter $filter,
        private readonly GreedySelector $selector,
        private readonly ConstraintValidator $validator,
        private readonly BacktrackingResolver $resolver,
    ) {
    }

    public function generate(Blueprint $blueprint): GenerationResult
    {
        $compiled = $this->compiler->compile($blueprint);

        // Pass 1 — greedy (LRU) fill.
        $greedy = $this->greedyFill($compiled);
        $constraints = $this->validator->validate($compiled, $greedy);

        if ($this->isComplete($compiled, $greedy) && $this->validator->allPass($constraints)) {
            return new GenerationResult(true, $compiled, $greedy, $constraints, []);
        }

        // Pass 2 — backtracking repair for a fully-valid assignment.
        $candidatesBySlot = $this->poolsBySlot($compiled);
        $resolved = $this->resolver->resolve($compiled, $candidatesBySlot);

        if ($resolved !== null) {
            $constraints = $this->validator->validate($compiled, $resolved);

            return new GenerationResult(true, $compiled, $resolved, $constraints, []);
        }

        // Infeasible — return the best-effort partial plus the shortfall.
        $missing = $this->computeMissingSlots($compiled, $greedy, $candidatesBySlot);

        return new GenerationResult(false, $compiled, $greedy, $constraints, $missing);
    }

    /**
     * Fill each slot in order with the greedy pick, excluding questions already
     * chosen in this paper.
     *
     * @return array<int, Question>
     */
    private function greedyFill(CompiledBlueprint $compiled): array
    {
        $selections = [];
        $usedIds = [];

        foreach ($compiled->slots as $slot) {
            $pool = $this->filter->for($slot, $compiled, $usedIds);
            $pick = $this->selector->pick($pool);

            if ($pick !== null) {
                $selections[$slot->index] = $pick;
                $usedIds[] = $pick->id;
            }
        }

        return $selections;
    }

    /**
     * Full candidate pool per slot (no per-paper exclusions — the resolver
     * enforces uniqueness itself).
     *
     * @return array<int, Collection<int, Question>>
     */
    private function poolsBySlot(CompiledBlueprint $compiled): array
    {
        $pools = [];
        foreach ($compiled->slots as $slot) {
            $pools[$slot->index] = $this->filter->for($slot, $compiled);
        }

        return $pools;
    }

    /** @param  array<int, Question>  $selections */
    private function isComplete(CompiledBlueprint $compiled, array $selections): bool
    {
        return count($selections) === count($compiled->slots);
    }

    /**
     * Determine exactly what the bank lacks. First by raw supply: for each
     * (section, type, marks) signature, deficit = slots required − distinct
     * approved questions available. If supply is sufficient everywhere but the
     * paper is still infeasible, the cause is coverage: name each allowed unit
     * the best-effort partial could not cover.
     *
     * @param  array<int, Question>  $partial
     * @param  array<int, Collection<int, Question>>  $candidatesBySlot
     * @return MissingSlot[]
     */
    private function computeMissingSlots(
        CompiledBlueprint $compiled,
        array $partial,
        array $candidatesBySlot,
    ): array {
        // Group slots by (section, type, marks).
        $groups = [];
        foreach ($compiled->slots as $slot) {
            $key = $slot->sectionLabel.'|'.$slot->type.'|'.$slot->marks;
            if (! isset($groups[$key])) {
                $groups[$key] = ['slot' => $slot, 'required' => 0];
            }
            $groups[$key]['required']++;
        }

        $missing = [];
        foreach ($groups as $group) {
            /** @var Slot $slot */
            $slot = $group['slot'];
            $available = ($candidatesBySlot[$slot->index] ?? collect())->count();
            $deficit = $group['required'] - $available;

            if ($deficit > 0) {
                $missing[] = new MissingSlot(
                    sectionLabel: $slot->sectionLabel,
                    type: $slot->type,
                    marks: $slot->marks,
                    unit: null,
                    need: $deficit,
                );
            }
        }

        if (! empty($missing)) {
            return $missing;
        }

        // Supply is adequate but coverage is impossible: name the uncovered units.
        $covered = collect($partial)->map(fn (Question $q) => $q->unit_id)->unique()->all();
        $uncovered = array_diff($compiled->allowedUnitIds, $covered);
        $firstSlot = $compiled->slots[0] ?? null;

        foreach ($uncovered as $unitId) {
            $missing[] = new MissingSlot(
                sectionLabel: $firstSlot?->sectionLabel ?? 'Section',
                type: $firstSlot?->type ?? 'short',
                marks: $firstSlot?->marks ?? 0,
                unit: $compiled->unitNames[$unitId] ?? "Unit {$unitId}",
                need: 1,
            );
        }

        return $missing;
    }
}
