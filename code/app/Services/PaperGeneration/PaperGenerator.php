<?php

namespace App\Services\PaperGeneration;

use App\Models\Blueprint;
use App\Models\Paper;
use App\Models\PaperQuestion;
use App\Models\Question;
use App\Services\PaperGeneration\Support\CompiledBlueprint;
use App\Services\PaperGeneration\Support\GenerationResult;
use App\Services\PaperGeneration\Support\MissingSlot;
use App\Services\PaperGeneration\Support\Slot;
use Illuminate\Database\Eloquent\Builder;
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

        // Cross-paper repetition rule (M3): exclude every question used in the
        // most recent N papers by the same owner for the same subject. Computed
        // once and applied to both the greedy and the backtracking candidate
        // pools so backtracking can't reintroduce an excluded question.
        $lastN = $this->lastNExclusion($blueprint, $compiled);

        // Pass 1 — greedy (LRU) fill.
        $greedy = $this->greedyFill($compiled, $lastN);
        $constraints = $this->validator->validate($compiled, $greedy);

        if ($this->isComplete($compiled, $greedy) && $this->validator->allPass($constraints)) {
            return new GenerationResult(true, $compiled, $greedy, $constraints, []);
        }

        // Pass 2 — backtracking repair for a fully-valid assignment. Skipped
        // when the blueprint is structurally infeasible (coverage outstrips the
        // paper's unit capacity, or caps can't fill the slots): no assignment
        // can exist, so the DFS would only burn its iteration budget.
        $candidatesBySlot = $this->poolsBySlot($compiled, $lastN);
        $resolved = $compiled->structurallyInfeasible()
            ? null
            : $this->resolver->resolve($compiled, $candidatesBySlot);

        if ($resolved !== null) {
            $constraints = $this->validator->validate($compiled, $resolved);

            return new GenerationResult(true, $compiled, $resolved, $constraints, []);
        }

        // Infeasible — return the best-effort partial plus the shortfall.
        $missing = $this->computeMissingSlots($compiled, $greedy, $candidatesBySlot);

        return new GenerationResult(false, $compiled, $greedy, $constraints, $missing);
    }

    /**
     * Build the cross-paper exclusion closure for this run, or null when the
     * blueprint disables it (lastNPapers <= 0) or there are no prior papers.
     *
     * @return (callable(Builder): void)|null
     */
    private function lastNExclusion(Blueprint $blueprint, CompiledBlueprint $compiled): ?callable
    {
        if ($compiled->lastNPapers <= 0) {
            return null;
        }

        // Rolling last-N window, subject-wide: my own generated papers PLUS any
        // imported past exam (M3.1) for this subject — so a generated paper avoids
        // questions that were on real historical exams, for every teacher. Imported
        // papers age out normally (pure rolling; no special treatment).
        $recentPaperIds = Paper::query()
            ->where('subject_id', $blueprint->subject_id)
            ->where(fn (Builder $q) => $q
                ->where('owner_id', $blueprint->owner_id)
                ->orWhere('origin', 'imported'))
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit($compiled->lastNPapers)
            ->pluck('id');

        if ($recentPaperIds->isEmpty()) {
            return null;
        }

        $excludedIds = PaperQuestion::query()
            ->whereIn('paper_id', $recentPaperIds)
            ->pluck('question_id')
            ->unique()
            ->values()
            ->all();

        if (empty($excludedIds)) {
            return null;
        }

        return fn (Builder $query) => $query->whereNotIn('id', $excludedIds);
    }

    /**
     * Fill each slot in order with the greedy pick, excluding questions already
     * chosen in this paper and (via $lastN) those used in the last N papers.
     * Threads the running covered-unit set into the selector (coverage-first
     * rank) and rejects candidates that would exceed a per-unit cap.
     *
     * @param  (callable(Builder): void)|null  $lastN
     * @return array<int, Question>
     */
    private function greedyFill(CompiledBlueprint $compiled, ?callable $lastN = null): array
    {
        $selections = [];
        $usedIds = [];
        $covered = [];
        $unitUse = [];

        foreach ($compiled->slots as $slot) {
            $pool = $this->filter->for($slot, $compiled, $usedIds, $lastN);

            if ($compiled->unitCaps !== []) {
                $pool = $pool->reject(
                    fn (Question $q) => $this->bustsCap($q, $compiled->unitCaps, $unitUse)
                )->values();
            }

            $uncovered = array_values(array_diff($compiled->allowedUnitIds, $covered));
            $pick = $this->selector->pick($pool, $uncovered);

            if ($pick !== null) {
                $selections[$slot->index] = $pick;
                $usedIds[] = $pick->id;

                foreach ($pick->taggedUnitIds() as $unitId) {
                    $covered[] = $unitId;
                    if (isset($compiled->unitCaps[$unitId])) {
                        $unitUse[$unitId] = ($unitUse[$unitId] ?? 0) + 1;
                    }
                }
            }
        }

        return $selections;
    }

    /**
     * True when selecting $question would push any of its tagged capped units
     * past its maximum. A multi-unit question counts 1 toward EVERY tagged
     * capped unit. (The BacktrackingResolver prunes with the same rule.)
     *
     * @param  array<int, int>  $caps  unitId => max
     * @param  array<int, int>  $unitUse  unitId => questions already counted
     */
    private function bustsCap(Question $question, array $caps, array $unitUse): bool
    {
        foreach ($question->taggedUnitIds() as $unitId) {
            if (isset($caps[$unitId]) && ($unitUse[$unitId] ?? 0) + 1 > $caps[$unitId]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Full candidate pool per slot (no per-paper exclusions — the resolver
     * enforces uniqueness itself — but the cross-paper $lastN rule still applies).
     *
     * @param  (callable(Builder): void)|null  $lastN
     * @return array<int, Collection<int, Question>>
     */
    private function poolsBySlot(CompiledBlueprint $compiled, ?callable $lastN = null): array
    {
        $pools = [];
        foreach ($compiled->slots as $slot) {
            $pools[$slot->index] = $this->filter->for($slot, $compiled, [], $lastN);
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
            $pool = $candidatesBySlot[$slot->index] ?? collect();
            $deficit = $group['required'] - $pool->count();

            if ($deficit > 0) {
                // Enrich the shortfall with concrete unit ids for the M5 top-up. On a
                // unit-restricted blueprint an AI question must land on an allowed unit
                // or CandidateFilter's allowed-units check hides it; target the allowed
                // units with the fewest matching approved questions so we fill the real
                // gap (a unit with zero approved questions is thus preferred). When the
                // coverage rule demands more units than the paper has slots, each
                // top-up question must pull double duty — target the TWO scarcest
                // units so it spans both. An unrestricted blueprint keeps unitIds
                // empty (no unit filter applies).
                $byScarcity = $this->unitsByScarcity($compiled, $pool);
                $span = count($compiled->allowedUnitIds) > count($compiled->slots) ? 2 : 1;
                $targetUnitIds = array_slice($byScarcity, 0, $span);

                $missing[] = new MissingSlot(
                    sectionLabel: $slot->sectionLabel,
                    type: $slot->type,
                    marks: $slot->marks,
                    unit: $this->unitLabel($compiled, $targetUnitIds),
                    need: $deficit,
                    unitIds: $targetUnitIds,
                );
            }
        }

        if (! empty($missing)) {
            return $missing;
        }

        // Supply is adequate but coverage is impossible: name the uncovered units.
        // Union semantics — a multi-unit question covers every tagged unit.
        $covered = collect($partial)->flatMap(fn (Question $q) => $q->taggedUnitIds())->unique()->all();
        $uncovered = array_values(array_diff($compiled->allowedUnitIds, $covered));
        $firstSlot = $compiled->slots[0] ?? null;

        $allPool = collect($candidatesBySlot)->flatMap(fn (Collection $pool) => $pool)->unique('id');
        $byScarcity = $this->unitsByScarcity($compiled, $allPool);

        if (count($compiled->allowedUnitIds) <= count($compiled->slots)) {
            // Enough slots for one unit apiece: single-unit top-ups suffice.
            $chunks = array_map(
                fn (int $unitId) => [$unitId],
                array_values(array_intersect($byScarcity, $uncovered)),
            );
        } else {
            // More units than slots: only unit-spanning questions can close the
            // gap — a valid paper needs (units − slots) questions that each cover
            // two units. Pair up the scarcest units for those, and top up any
            // uncovered unit left outside the pairs with a single. (units > 2×slots
            // is structurally infeasible and never dispatched.)
            $pairCount = count($compiled->allowedUnitIds) - count($compiled->slots);
            $pairUnits = array_slice($byScarcity, 0, 2 * $pairCount);
            $chunks = array_chunk($pairUnits, 2);

            foreach (array_diff($uncovered, $pairUnits) as $unitId) {
                $chunks[] = [$unitId];
            }
        }

        foreach ($chunks as $unitIds) {
            $missing[] = new MissingSlot(
                sectionLabel: $firstSlot?->sectionLabel ?? 'Section',
                type: $firstSlot?->type ?? 'short',
                marks: $firstSlot?->marks ?? 0,
                unit: $this->unitLabel($compiled, $unitIds),
                need: 1,
                unitIds: $unitIds,
            );
        }

        return $missing;
    }

    /**
     * Allowed units ordered by how few matching approved questions the candidate
     * pool holds (scarcest first), or [] when the blueprint imposes no unit
     * restriction. Units with zero approved questions (absent from the pool)
     * count as 0 and so come first. Deterministic tie-break by ascending unit id.
     *
     * @param  Collection<int, Question>  $pool
     * @return int[]
     */
    private function unitsByScarcity(CompiledBlueprint $compiled, Collection $pool): array
    {
        if (empty($compiled->allowedUnitIds)) {
            return [];
        }

        // Supply per unit counts every tagged unit (a Units 2+3 question is
        // supply for both), mirroring CandidateFilter's any-overlap rule.
        $countsByUnit = $pool
            ->flatMap(fn (Question $q) => $q->taggedUnitIds())
            ->countBy();

        $allowed = $compiled->allowedUnitIds;
        sort($allowed);

        return collect($allowed)
            ->sortBy(fn (int $unitId) => $countsByUnit->get($unitId, 0))
            ->values()
            ->all();
    }

    /** Display label for a target unit set, e.g. "Trees" or "Trees + Graphs". */
    private function unitLabel(CompiledBlueprint $compiled, array $unitIds): ?string
    {
        if ($unitIds === []) {
            return null;
        }

        $names = array_map(
            fn (int $unitId) => $compiled->unitNames[$unitId] ?? "Unit {$unitId}",
            $unitIds,
        );

        return implode(' + ', $names);
    }
}
