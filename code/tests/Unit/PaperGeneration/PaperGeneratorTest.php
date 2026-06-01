<?php

namespace Tests\Unit\PaperGeneration;

use App\Models\Blueprint;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaperGeneration\BacktrackingResolver;
use App\Services\PaperGeneration\BlueprintCompiler;
use App\Services\PaperGeneration\CandidateFilter;
use App\Services\PaperGeneration\ConstraintValidator;
use App\Services\PaperGeneration\GreedySelector;
use App\Services\PaperGeneration\PaperGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     */
    private function makeBlueprint(Subject $subject, array $sections, array $allowedUnitNames, int $totalMarks): Blueprint
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
                    'unitAllocations' => [],
                    'exclusionRules' => ['lastNPapers' => 0, 'reuseThreshold' => 3],
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
}
