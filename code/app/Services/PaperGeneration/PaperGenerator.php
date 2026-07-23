<?php

namespace App\Services\PaperGeneration;

use App\Models\Blueprint;
use App\Models\Paper;
use App\Models\PaperQuestion;
use App\Models\Question;
use App\Services\PaperGeneration\Contracts\SimilarityGuard;
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

    /**
     * @param  int|null  $seed  per-run tie-break seed; null = the legacy fixed
     *                          (id-ordered) paper. A distinct seed yields a
     *                          distinct valid paper, so "regenerate" and two
     *                          teachers no longer collide on one output.
     * @param  SimilarityGuard|null  $guard  within-paper near-duplicate guard;
     *                          null = no semantic dedup (pure id-uniqueness).
     */
    public function generate(Blueprint $blueprint, ?int $seed = null, ?SimilarityGuard $guard = null): GenerationResult
    {
        $guard ??= new NullSimilarityGuard;
        $compiled = $this->compiler->compile($blueprint);

        // Candidate-exclusion rules, composed into one closure and applied to
        // both the greedy and backtracking pools (so backtracking can't
        // reintroduce an excluded question):
        //   - lastNExclusion (M3): questions used in the most recent N papers.
        //   - examYearExclusion (post-M6): questions whose provenance year is in
        //     the last N years — "don't repeat recent exams' questions".
        $exclusions = $this->composeExclusions([
            $this->lastNExclusion($blueprint, $compiled),
            $this->examYearExclusion($blueprint, $compiled),
        ]);

        // Pass 1 — greedy (LRU) fill.
        $greedy = $this->greedyFill($compiled, $exclusions, $seed, $guard);
        $constraints = $this->validator->validate($compiled, $greedy);

        if ($this->isComplete($compiled, $greedy) && $this->validator->allPass($constraints)) {
            return new GenerationResult(true, $compiled, $greedy, $constraints, []);
        }

        // Pass 2 — backtracking repair for a fully-valid assignment. Skipped
        // when the blueprint is structurally infeasible (coverage outstrips the
        // paper's unit capacity, or caps can't fill the slots): no assignment
        // can exist, so the DFS would only burn its iteration budget.
        //
        // Similarity is deliberately NOT enforced here: it is a soft preference,
        // not a blueprint constraint, so it must never turn a satisfiable paper
        // infeasible. The greedy pass already prefers dissimilar questions; the
        // rare repair pass optimises for a valid assignment above all.
        $candidatesBySlot = $this->poolsBySlot($compiled, $exclusions);
        $resolved = $compiled->structurallyInfeasible()
            ? null
            : $this->resolver->resolve($compiled, $candidatesBySlot, $seed);

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
            // Only kept papers count toward the rolling window — an unsaved
            // auto-draft (including this run's own) must never exclude its
            // questions from the next generate/regenerate.
            ->where('status', '!=', 'draft')
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
     * Build the exam-year exclusion closure (post-M6), or null when disabled.
     *
     * Repetition by *provenance*: drop questions whose source exam year
     * (`attributes.exam_year`, set at extraction from the uploaded past paper's
     * year) falls in the N years immediately before the current calendar year —
     * so a new paper won't reuse questions that were on recent real exams.
     * N = 1 means "not last year's questions".
     *
     * Built as an excluded-**id** set (like lastNExclusion) so questions with no
     * recorded year stay eligible: a bare `whereNotIn('attributes->exam_year')`
     * would also drop null-year rows, which is the opposite of what we want.
     *
     * @return (callable(Builder): void)|null
     */
    private function examYearExclusion(Blueprint $blueprint, CompiledBlueprint $compiled): ?callable
    {
        if ($compiled->excludeExamYearsBack <= 0) {
            return null;
        }

        // Reference = current calendar year; window = the N years before it.
        $currentYear = (int) now()->year;
        $excludedYears = [];
        for ($y = $currentYear - 1; $y >= $currentYear - $compiled->excludeExamYearsBack; $y--) {
            $excludedYears[] = (string) $y; // exam_year is stored as a JSON string
        }

        $excludedIds = Question::query()
            ->where('subject_id', $blueprint->subject_id)
            ->whereIn('attributes->exam_year', $excludedYears)
            ->pluck('id')
            ->all();

        if (empty($excludedIds)) {
            return null;
        }

        return fn (Builder $query) => $query->whereNotIn('id', $excludedIds);
    }

    /**
     * Combine several optional candidate-exclusion closures into one, applied in
     * order — or null when none are active. Lets CandidateFilter keep taking a
     * single closure while the generator layers independent repetition rules.
     *
     * @param  array<int, (callable(Builder): void)|null>  $rules
     * @return (callable(Builder): void)|null
     */
    private function composeExclusions(array $rules): ?callable
    {
        $active = array_values(array_filter($rules));

        if ($active === []) {
            return null;
        }

        return function (Builder $query) use ($active) {
            foreach ($active as $rule) {
                $rule($query);
            }
        };
    }

    /**
     * Fill each slot in order with the greedy pick, excluding questions already
     * chosen in this paper and (via $exclusions) the composed repetition rules.
     * Threads the running covered-unit set into the selector (coverage-first
     * rank), rejects candidates that would exceed a per-unit cap, and — when a
     * SimilarityGuard is active — prefers candidates that are not near-duplicates
     * of questions already placed in this paper.
     *
     * @param  (callable(Builder): void)|null  $exclusions
     * @return array<int, Question>
     */
    private function greedyFill(
        CompiledBlueprint $compiled,
        ?callable $exclusions = null,
        ?int $seed = null,
        ?SimilarityGuard $guard = null,
    ): array {
        $guard ??= new NullSimilarityGuard;
        $selections = [];
        $usedIds = [];
        $covered = [];
        $unitUse = [];

        foreach ($compiled->slots as $slot) {
            $pool = $this->filter->for($slot, $compiled, $usedIds, $exclusions);

            if ($compiled->unitCaps !== []) {
                $pool = $pool->reject(
                    fn (Question $q) => $this->bustsCap($q, $compiled->unitCaps, $unitUse)
                )->values();
            }

            // Prefer questions that don't semantically repeat one already placed.
            // Soft, not hard: if dropping near-duplicates would empty the pool we
            // keep the full pool, so dedup never causes a shortfall. Fails open
            // when the guard has no signal (RAG off / index down).
            $guard->prime($pool);
            if ($selections !== []) {
                $fresh = $pool->reject(
                    fn (Question $q) => $guard->tooSimilar($q, $selections)
                )->values();

                if ($fresh->isNotEmpty()) {
                    $pool = $fresh;
                }
            }

            $uncovered = array_values(array_diff($compiled->allowedUnitIds, $covered));
            $pick = $this->selector->pick($pool, $uncovered, $seed);

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
     * enforces uniqueness itself — but the composed cross-paper/year
     * $exclusions still apply).
     *
     * @param  (callable(Builder): void)|null  $exclusions
     * @return array<int, Collection<int, Question>>
     */
    private function poolsBySlot(CompiledBlueprint $compiled, ?callable $exclusions = null): array
    {
        $pools = [];
        foreach ($compiled->slots as $slot) {
            $pools[$slot->index] = $this->filter->for($slot, $compiled, [], $exclusions);
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
