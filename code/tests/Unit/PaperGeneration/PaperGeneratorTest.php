<?php

namespace Tests\Unit\PaperGeneration;

use App\Models\Blueprint;
use App\Models\Paper;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaperGeneration\BacktrackingResolver;
use App\Services\PaperGeneration\BlueprintCompiler;
use App\Services\PaperGeneration\CandidateFilter;
use App\Services\PaperGeneration\ConstraintValidator;
use App\Services\PaperGeneration\Contracts\SimilarityGuard;
use App\Services\PaperGeneration\GreedySelector;
use App\Services\PaperGeneration\PaperGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PaperGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): PaperGenerator
    {
        return new PaperGenerator(
            new BlueprintCompiler,
            new CandidateFilter,
            new GreedySelector,
            new ConstraintValidator,
            new BacktrackingResolver,
        );
    }

    /**
     * Build (and persist) a blueprint for $subject.
     *
     * @param  array<int, array{name:string, type:string, count:int, marksEach:int}>  $sections
     * @param  string[]  $allowedUnitNames
     * @param  array<string, array<int, array{marks:int, count:int}>>  $unitAllocations  per-unit MAX cap rows
     */
    private function makeBlueprint(Subject $subject, array $sections, array $allowedUnitNames, int $totalMarks, array $unitAllocations = [], int $excludeExamYearsBack = 0): Blueprint
    {
        $sectionDefs = [];
        foreach ($sections as $i => $s) {
            $sectionDefs[] = [
                'id' => $i + 1,
                'name' => $s['name'],
                'type' => $s['type'],
                'count' => $s['count'],
                'marksEach' => $s['marksEach'],
                'mandatory' => true,
            ];
        }

        $unitRules = [];
        foreach ($allowedUnitNames as $name) {
            $unitRules[$name] = true;
        }

        return Blueprint::factory()
            ->for(User::factory()->state(['role' => 'teacher']), 'owner')
            ->for($subject)
            ->create([
                'total_marks' => $totalMarks,
                'definition' => [
                    'sections' => $sectionDefs,
                    'unitRules' => $unitRules,
                    'unitAllocations' => $unitAllocations,
                    'exclusionRules' => ['lastNPapers' => 0, 'excludeExamYearsBack' => $excludeExamYearsBack],
                ],
            ]);
    }

    /** Create $count approved questions of a given type/marks in a unit. */
    private function seedQuestions(Subject $subject, Unit $unit, string $type, int $marks, int $count, int $usedCount = 0): void
    {
        Question::factory()->count($count)->create([
            'subject_id' => $subject->id,
            'unit_id' => $unit->id,
            'type' => $type,
            'marks' => $marks,
            'status' => 'approved',
            'used_count' => $usedCount,
        ]);
    }

    public function test_generates_a_valid_paper_with_all_constraints_passing_for_a_feasible_blueprint(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);
        $u3 = Unit::factory()->for($subject)->create(['name' => 'Unit 3', 'position' => 3]);

        foreach ([$u1, $u2, $u3] as $u) {
            $this->seedQuestions($subject, $u, 'short', 4, 2);
            $this->seedQuestions($subject, $u, 'long', 10, 2);
        }

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
            ['name' => 'Section B', 'type' => 'Long Answer', 'count' => 3, 'marksEach' => 10],
        ], ['Unit 1', 'Unit 2', 'Unit 3'], 42);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $this->assertCount(6, $result->selections);
        $this->assertEmpty($result->missingSlots);
        $this->assertTrue((new ConstraintValidator)->allPass($result->constraintResults));

        $marks = collect($result->constraintResults)->firstWhere('label', 'Total marks');
        $this->assertSame('42', $marks->got);
        $this->assertTrue($marks->pass);
    }

    public function test_enforces_unit_coverage_every_allowed_unit_appears(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);
        $u3 = Unit::factory()->for($subject)->create(['name' => 'Unit 3', 'position' => 3]);

        $this->seedQuestions($subject, $u1, 'short', 4, 3);
        $this->seedQuestions($subject, $u2, 'short', 4, 3);
        $this->seedQuestions($subject, $u3, 'short', 4, 3);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2', 'Unit 3'], 12);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);

        $coveredUnitIds = collect($result->selections)->map(fn (Question $q) => $q->unit_id)->unique();
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id, $u3->id], $coveredUnitIds->all());

        $coverage = collect($result->constraintResults)->firstWhere('label', 'Unit coverage');
        $this->assertTrue($coverage->pass);
        $this->assertSame('3 units', $coverage->got);
    }

    public function test_never_repeats_a_question_within_a_single_paper(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        // Exactly enough distinct questions to fill 4 slots, no slack.
        $this->seedQuestions($subject, $u1, 'short', 4, 4);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 4, 'marksEach' => 4],
        ], ['Unit 1'], 16);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);

        $ids = collect($result->selections)->map(fn (Question $q) => $q->id);
        $this->assertSame($ids->count(), $ids->unique()->count());

        $norepeat = collect($result->constraintResults)->firstWhere('label', 'No repeated questions');
        $this->assertTrue($norepeat->pass);
    }

    public function test_reports_a_precise_shortfall_when_the_bank_is_too_thin(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        // Need 5 long/10 but only 2 exist.
        $this->seedQuestions($subject, $u1, 'long', 10, 2);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section B', 'type' => 'Long Answer', 'count' => 5, 'marksEach' => 10],
        ], ['Unit 1'], 50);

        $result = $this->generator()->generate($blueprint);

        $this->assertFalse($result->satisfiable);
        $this->assertCount(1, $result->missingSlots);

        $missing = $result->missingSlots[0];
        $this->assertSame('long', $missing->type);
        $this->assertSame(10, $missing->marks);
        $this->assertSame(3, $missing->need);

        // Best-effort partial still returns the two that could be filled.
        $this->assertCount(2, $result->selections);
    }

    public function test_excludes_questions_used_in_the_last_n_papers(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        // Six distinct short/4 questions: three will be "spent" by a prior paper.
        $this->seedQuestions($subject, $u1, 'short', 4, 6);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
        ], ['Unit 1'], 12);
        // Turn the cross-paper window on (helper defaults it to 0).
        $definition = $blueprint->definition;
        $definition['exclusionRules']['lastNPapers'] = 1;
        $blueprint->update(['definition' => $definition]);

        // Persist a prior paper owning the first three question ids.
        $spentIds = Question::where('subject_id', $subject->id)->orderBy('id')->limit(3)->pluck('id');
        $paper = \App\Models\Paper::create([
            'owner_id' => $blueprint->owner_id,
            'blueprint_id' => $blueprint->id,
            'subject_id' => $subject->id,
            'name' => 'Prior', 'total_marks' => 12, 'duration' => 60,
            'status' => 'draft', 'export_count' => 0, 'generated_at' => now(),
        ]);
        foreach ($spentIds as $i => $qid) {
            $paper->paperQuestions()->create([
                'question_id' => $qid, 'unit_id' => $u1->id,
                'section_label' => 'Section A', 'display_no' => $i + 1, 'marks' => 4, 'is_ai' => false,
            ]);
        }

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $chosen = collect($result->selections)->map(fn (Question $q) => $q->id)->all();
        $this->assertEmpty(array_intersect($chosen, $spentIds->all()), 'Engine reused a last-N excluded question.');
    }

    public function test_backtracking_recovers_a_valid_paper_the_naive_greedy_pass_fails_to_find(): void
    {
        // The academic centerpiece. The greedy selector is LRU-only (unit-agnostic):
        // it fills both short/4 slots from Unit 1 (lowest used_count), leaving Unit 2
        // uncovered. A valid covering assignment exists, and backtracking finds it.
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);

        // Unit 1 has two fresh questions (used_count 0); Unit 2 has one stale one.
        $this->seedQuestions($subject, $u1, 'short', 4, 2, usedCount: 0);
        $this->seedQuestions($subject, $u2, 'short', 4, 1, usedCount: 5);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2'], 8);

        $compiler = new BlueprintCompiler;
        $filter = new CandidateFilter;
        $selector = new GreedySelector;
        $validator = new ConstraintValidator;

        // Prove the naive greedy pass alone would violate unit coverage.
        $compiled = $compiler->compile($blueprint);
        $greedy = [];
        $usedIds = [];
        foreach ($compiled->slots as $slot) {
            $pick = $selector->pick($filter->for($slot, $compiled, $usedIds));
            $greedy[$slot->index] = $pick;
            $usedIds[] = $pick->id;
        }
        $greedyCoverage = collect($greedy)->map(fn (Question $q) => $q->unit_id)->unique();
        $this->assertCount(1, $greedyCoverage); // greedy starved Unit 2

        // The full engine recovers a valid, fully-covering paper.
        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $coveredUnitIds = collect($result->selections)->map(fn (Question $q) => $q->unit_id)->unique();
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], $coveredUnitIds->all());
        $this->assertTrue($validator->allPass($result->constraintResults));
    }

    public function test_coverage_aware_greedy_covers_all_units_without_needing_backtracking(): void
    {
        // Same starvation setup as the backtracking test: LRU-only greedy would fill
        // both slots from fresh Unit 1 and starve Unit 2. The coverage-aware greedy
        // must prefer the stale Unit 2 question for uncovered-unit rank and produce
        // a valid paper on the FIRST pass (proved by driving greedyFill's logic via
        // pick() with the uncovered set, not just the end result).
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);

        $this->seedQuestions($subject, $u1, 'short', 4, 2, usedCount: 0);
        $this->seedQuestions($subject, $u2, 'short', 4, 1, usedCount: 5);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2'], 8);

        $compiler = new BlueprintCompiler;
        $filter = new CandidateFilter;
        $selector = new GreedySelector;

        $compiled = $compiler->compile($blueprint);
        $covered = [];
        $usedIds = [];
        $picks = [];
        foreach ($compiled->slots as $slot) {
            $uncovered = array_values(array_diff($compiled->allowedUnitIds, $covered));
            $pick = $selector->pick($filter->for($slot, $compiled, $usedIds), $uncovered);
            $picks[] = $pick;
            $usedIds[] = $pick->id;
            $covered = array_merge($covered, $pick->taggedUnitIds());
        }

        $greedyCoverage = collect($picks)->flatMap(fn (Question $q) => $q->taggedUnitIds())->unique();
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], $greedyCoverage->all(),
            'Coverage-aware greedy still starved a unit.');
    }

    public function test_unit_cap_limits_how_many_questions_a_unit_contributes(): void
    {
        // Unit 1 is rich and fresh; Unit 2 thin and stale. Uncapped, Unit 1 would fill
        // 3 of the 4 slots. Capped at 2, exactly 2 of the paper's questions may tag it.
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);

        $this->seedQuestions($subject, $u1, 'short', 4, 4, usedCount: 0);
        $this->seedQuestions($subject, $u2, 'short', 4, 3, usedCount: 5);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 4, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2'], 16, unitAllocations: [
            'Unit 1' => [['marks' => 4, 'count' => 2]],
        ]);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $u1Count = collect($result->selections)
            ->filter(fn (Question $q) => in_array($u1->id, $q->taggedUnitIds(), true))
            ->count();
        $this->assertLessThanOrEqual(2, $u1Count, 'Unit cap was exceeded.');

        $maximums = collect($result->constraintResults)->firstWhere('label', 'Unit maximums');
        $this->assertNotNull($maximums);
        $this->assertTrue($maximums->pass);
    }

    public function test_a_multi_unit_question_counts_toward_every_tagged_capped_unit(): void
    {
        // One question spans Units 1+2, both capped at 1. Selecting it exhausts BOTH
        // caps, so the remaining slot must come from Unit 3.
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);
        $u3 = Unit::factory()->for($subject)->create(['name' => 'Unit 3', 'position' => 3]);

        $spanning = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $u1->id,
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);
        $spanning->syncUnitLinks([$u1->id, $u2->id]);
        $this->seedQuestions($subject, $u1, 'short', 4, 2, usedCount: 1);
        $this->seedQuestions($subject, $u2, 'short', 4, 2, usedCount: 1);
        $this->seedQuestions($subject, $u3, 'short', 4, 2, usedCount: 1);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2', 'Unit 3'], 8, unitAllocations: [
            'Unit 1' => [['marks' => 4, 'count' => 1]],
            'Unit 2' => [['marks' => 4, 'count' => 1]],
        ]);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $selections = collect($result->selections);
        $this->assertContains($spanning->id, $selections->map(fn (Question $q) => $q->id)->all(),
            'The spanning question is the only way to cover 3 units in 2 slots.');

        $useByUnit = $selections->flatMap(fn (Question $q) => $q->taggedUnitIds())->countBy();
        $this->assertLessThanOrEqual(1, $useByUnit->get($u1->id, 0));
        $this->assertLessThanOrEqual(1, $useByUnit->get($u2->id, 0));
    }

    public function test_caps_summing_below_the_slot_count_are_structurally_infeasible(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);

        $this->seedQuestions($subject, $u1, 'short', 4, 5);
        $this->seedQuestions($subject, $u2, 'short', 4, 5);

        // 4 slots but every unit capped and caps sum to 3 — no assignment can exist,
        // and AI expansion can't help (every new question hits a full unit).
        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 4, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2'], 16, unitAllocations: [
            'Unit 1' => [['marks' => 4, 'count' => 2]],
            'Unit 2' => [['marks' => 4, 'count' => 1]],
        ]);

        $result = $this->generator()->generate($blueprint);

        $this->assertFalse($result->satisfiable);
        $this->assertTrue($result->coverageStructurallyInfeasible());
        $this->assertStringContainsString('maximums', (string) $result->coverageDeficitMessage());
    }

    public function test_multi_unit_questions_cover_more_units_than_slots(): void
    {
        // The supervisor scenario in miniature: 3 mandated units, 2 slots. Impossible
        // with single-unit questions; a Units 2+3 spanning question makes it valid.
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = Unit::factory()->for($subject)->create(['name' => 'Unit 2', 'position' => 2]);
        $u3 = Unit::factory()->for($subject)->create(['name' => 'Unit 3', 'position' => 3]);

        $this->seedQuestions($subject, $u1, 'short', 4, 2);
        $spanning = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $u2->id,
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);
        $spanning->syncUnitLinks([$u2->id, $u3->id]);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2', 'Unit 3'], 8);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $covered = collect($result->selections)->flatMap(fn (Question $q) => $q->taggedUnitIds())->unique();
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id, $u3->id], $covered->all());
    }

    public function test_missing_slots_pair_the_scarcest_units_when_units_exceed_slots(): void
    {
        // 4 mandated units, 2 slots, only single-unit supply: infeasible, and the
        // shortfall must request PAIRED units (units − slots = 2 pairs of the four
        // scarcest) so the AI top-up can close the gap in one round.
        $subject = Subject::factory()->create();
        $units = collect(range(1, 4))->map(fn ($n) => Unit::factory()->for($subject)->create([
            'name' => "Unit {$n}", 'position' => $n,
        ]));
        foreach ($units as $u) {
            $this->seedQuestions($subject, $u, 'short', 4, 2);
        }

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], $units->pluck('name')->all(), 8);

        $result = $this->generator()->generate($blueprint);

        $this->assertFalse($result->satisfiable);
        $this->assertFalse($result->coverageStructurallyInfeasible(), '4 units ≤ 2×2 slots is fixable.');
        $this->assertNotEmpty($result->missingSlots);
        foreach ($result->missingSlots as $missing) {
            $this->assertCount(2, $missing->unitIds, 'Expected pair-targeted missing slots.');
        }
        // Deterministic pairing: equal supply ties break by ascending unit id.
        $this->assertSame(
            [[$units[0]->id, $units[1]->id], [$units[2]->id, $units[3]->id]],
            array_map(fn ($m) => $m->unitIds, $result->missingSlots),
        );
    }

    public function test_empty_unit_rules_leave_coverage_and_caps_inert(): void
    {
        // Regression: with no unitRules there is no coverage rule and caps are ignored
        // even if allocation rows exist — behaviour identical to plain LRU.
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $this->seedQuestions($subject, $u1, 'short', 4, 3);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
        ], [], 12, unitAllocations: [
            'Unit 1' => [['marks' => 4, 'count' => 1]], // must be ignored: unit not enabled
        ]);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $this->assertCount(3, $result->selections);
        $this->assertNull(collect($result->constraintResults)->firstWhere('label', 'Unit maximums'));
        $this->assertNull(collect($result->constraintResults)->firstWhere('label', 'Unit coverage'));
    }

    public function test_imported_exam_ages_out_of_the_rolling_window(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        // Q1/Q2 are the "real exam" questions; F1 is filler so a newer paper can be
        // recorded without re-spending Q1/Q2.
        $this->seedQuestions($subject, $u1, 'short', 4, 3);
        [$q1, $q2, $f1] = Question::where('subject_id', $subject->id)->orderBy('id')->get()->all();

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 1, 'marksEach' => 4],
        ], ['Unit 1'], 4);
        $definition = $blueprint->definition;
        $definition['exclusionRules']['lastNPapers'] = 1;
        $blueprint->update(['definition' => $definition]);

        // An imported exam (admin-owned, subject-wide) claims Q1 and Q2.
        $imported = Paper::factory()->imported()->create([
            'owner_id' => User::factory()->state(['role' => 'admin']),
            'subject_id' => $subject->id, 'generated_at' => now()->subYear(),
        ]);
        foreach ([$q1, $q2] as $i => $q) {
            $imported->paperQuestions()->create([
                'question_id' => $q->id, 'unit_id' => $u1->id,
                'section_label' => 'Imported', 'display_no' => $i + 1, 'marks' => 4, 'is_ai' => false,
            ]);
        }

        // While the imported exam is the newest in the window, Q1/Q2 are excluded → only F1 is picked.
        $first = $this->generator()->generate($blueprint);
        $this->assertTrue($first->satisfiable);
        $this->assertSame([$f1->id], collect($first->selections)->map(fn (Question $q) => $q->id)->all());

        // A newer owner-generated paper (spending F1) bumps the imported exam out of the size-1 window.
        $newer = Paper::factory()->create([
            'owner_id' => $blueprint->owner_id, 'blueprint_id' => $blueprint->id,
            'subject_id' => $subject->id, 'origin' => 'generated', 'generated_at' => now(),
        ]);
        $newer->paperQuestions()->create([
            'question_id' => $f1->id, 'unit_id' => $u1->id,
            'section_label' => 'Section A', 'display_no' => 1, 'marks' => 4, 'is_ai' => false,
        ]);

        // Now the window holds only the newer paper; the imported exam aged out, so Q1/Q2 are eligible again.
        $second = $this->generator()->generate($blueprint);
        $this->assertTrue($second->satisfiable);
        $chosen = collect($second->selections)->map(fn (Question $q) => $q->id)->all();
        $this->assertCount(1, $chosen);
        $this->assertContains($chosen[0], [$q1->id, $q2->id], 'Imported exam did not age out of the rolling window.');
    }

    public function test_no_seed_preserves_the_legacy_deterministic_id_ordering(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        // Slack: 6 candidates for 3 slots, all used_count 0 → tie-break decides.
        $this->seedQuestions($subject, $u1, 'short', 4, 6);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
        ], ['Unit 1'], 12);

        // With no seed the greedy pass falls to ascending id: the three lowest ids.
        $expected = Question::where('subject_id', $subject->id)->orderBy('id')->limit(3)->pluck('id')->all();

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $this->assertSame($expected, collect($result->selections)->map(fn (Question $q) => (int) $q->id)->values()->all());
    }

    public function test_same_seed_is_reproducible_and_different_seeds_vary_the_paper(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        // Generous slack so a reshuffle has room to land differently.
        $this->seedQuestions($subject, $u1, 'short', 4, 20);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 5, 'marksEach' => 4],
        ], ['Unit 1'], 20);

        $ids = fn ($r) => collect($r->selections)->map(fn (Question $q) => (int) $q->id)->values()->all();

        $a1 = $ids($this->generator()->generate($blueprint, seed: 111));
        $a2 = $ids($this->generator()->generate($blueprint, seed: 111));
        $b = $ids($this->generator()->generate($blueprint, seed: 999));

        // Reproducible: identical seed → identical paper.
        $this->assertSame($a1, $a2);
        // Varied: a different seed selects a different set (given the slack above).
        $this->assertNotSame($a1, $b);
        // Still a valid paper: five distinct questions.
        $this->assertCount(5, array_unique($b));
    }

    public function test_greedy_pass_avoids_a_near_duplicate_when_an_alternative_exists(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $this->seedQuestions($subject, $u1, 'short', 4, 3);

        [$a, $b, $c] = Question::where('subject_id', $subject->id)->orderBy('id')->get()->all();

        // Mark A and B as semantic duplicates; C is distinct. Two slots.
        $guard = $this->fakeGuard([$a->id => 1, $b->id => 1, $c->id => 2]);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1'], 8);

        $result = $this->generator()->generate($blueprint, seed: null, guard: $guard);

        $this->assertTrue($result->satisfiable);
        $chosen = collect($result->selections)->map(fn (Question $q) => (int) $q->id)->all();
        // A is picked first (lowest id); the guard then steers slot 2 to C, not B.
        $this->assertSame([$a->id, $c->id], $chosen);
    }

    public function test_duplicate_avoidance_never_causes_a_shortfall(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        // Only two questions, and the guard says they are duplicates of each other.
        $this->seedQuestions($subject, $u1, 'short', 4, 2);

        [$a, $b] = Question::where('subject_id', $subject->id)->orderBy('id')->get()->all();
        $guard = $this->fakeGuard([$a->id => 1, $b->id => 1]);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1'], 8);

        $result = $this->generator()->generate($blueprint, seed: null, guard: $guard);

        // Soft preference: with no fresh alternative the paper is still filled.
        $this->assertTrue($result->satisfiable);
        $this->assertSame([$a->id, $b->id], collect($result->selections)->map(fn (Question $q) => (int) $q->id)->all());
    }

    public function test_excludes_questions_from_a_recent_exam_year(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        $lastYear = (string) (now()->year - 1);

        // Both fit the slot; the stale one has the lower id, so plain LRU would
        // pick it first — proving the year rule is what removes it.
        $stale = $this->seedQuestionWithYear($subject, $u1, $lastYear);
        $fresh = $this->seedQuestionWithYear($subject, $u1, null);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 1, 'marksEach' => 4],
        ], ['Unit 1'], 4, excludeExamYearsBack: 1);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        // Last year's question is filtered out; the year-less one is picked.
        $this->assertSame([$fresh->id], collect($result->selections)->map(fn (Question $q) => (int) $q->id)->all());
        $this->assertNotContains($stale->id, collect($result->selections)->map(fn (Question $q) => (int) $q->id)->all());
    }

    public function test_exam_year_exclusion_is_inert_when_zero(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        // A last-year question is eligible again when the rule is off (default 0).
        $stale = $this->seedQuestionWithYear($subject, $u1, (string) (now()->year - 1));
        $this->seedQuestionWithYear($subject, $u1, null);

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 1, 'marksEach' => 4],
        ], ['Unit 1'], 4);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        // Lowest id wins under plain LRU — the stale question is picked normally.
        $this->assertSame([$stale->id], collect($result->selections)->map(fn (Question $q) => (int) $q->id)->all());
    }

    public function test_exam_year_exclusion_only_covers_the_window_and_keeps_year_less_questions(): void
    {
        $subject = Subject::factory()->create();
        $u1 = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        // Window with N=1 is just {last year}. A two-years-ago question is OUTSIDE
        // it and stays eligible; a year-less question is always eligible.
        $older = $this->seedQuestionWithYear($subject, $u1, (string) (now()->year - 2));
        $noYear = $this->seedQuestionWithYear($subject, $u1, null);
        $recent = $this->seedQuestionWithYear($subject, $u1, (string) (now()->year - 1));

        $blueprint = $this->makeBlueprint($subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1'], 8, excludeExamYearsBack: 1);

        $result = $this->generator()->generate($blueprint);

        $this->assertTrue($result->satisfiable);
        $chosen = collect($result->selections)->map(fn (Question $q) => (int) $q->id)->all();
        // The two survivors fill both slots; last year's question is excluded.
        $this->assertEqualsCanonicalizing([$older->id, $noYear->id], $chosen);
        $this->assertNotContains($recent->id, $chosen);
    }

    /** Create one approved question in $unit, optionally tagged with an exam year. */
    private function seedQuestionWithYear(Subject $subject, Unit $unit, ?string $examYear): Question
    {
        return Question::factory()->create([
            'subject_id' => $subject->id,
            'unit_id' => $unit->id,
            'type' => 'short',
            'marks' => 4,
            'status' => 'approved',
            'attributes' => $examYear !== null ? ['exam_year' => $examYear] : null,
        ]);
    }

    /**
     * A deterministic in-memory SimilarityGuard: two questions are "duplicates"
     * when they share a group key. Lets the engine's dedup path be tested with
     * no Qdrant/embeddings.
     *
     * @param  array<int, int>  $groupByQuestionId
     */
    private function fakeGuard(array $groupByQuestionId): SimilarityGuard
    {
        return new class($groupByQuestionId) implements SimilarityGuard
        {
            /** @param array<int, int> $group */
            public function __construct(private array $group) {}

            public function prime(Collection $questions): void {}

            public function tooSimilar(Question $candidate, array $chosen): bool
            {
                $group = $this->group[(int) $candidate->id] ?? null;
                if ($group === null) {
                    return false;
                }
                foreach ($chosen as $c) {
                    if (($this->group[(int) $c->id] ?? null) === $group) {
                        return true;
                    }
                }

                return false;
            }
        };
    }
}
