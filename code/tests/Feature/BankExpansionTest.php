<?php

namespace Tests\Feature;

use App\Jobs\ExpandQuestionBank;
use App\Models\Blueprint;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Services\AiExpansion\GroundingBuilder;
use App\Services\PythonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankExpansionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{name:string, type:string, count:int, marksEach:int}>  $sections
     * @param  string[]  $allowedUnitNames
     */
    private function blueprintFor(User $owner, Subject $subject, array $sections, array $allowedUnitNames, int $totalMarks): Blueprint
    {
        $sectionDefs = [];
        foreach ($sections as $i => $s) {
            $sectionDefs[] = $s + ['id' => $i + 1, 'mandatory' => true];
        }
        $unitRules = [];
        foreach ($allowedUnitNames as $name) {
            $unitRules[$name] = true;
        }

        return Blueprint::factory()->for($owner, 'owner')->for($subject)->create([
            'total_marks' => $totalMarks,
            'definition' => [
                'sections' => $sectionDefs,
                'unitRules' => $unitRules,
                'unitAllocations' => [],
                'exclusionRules' => ['lastNPapers' => 0, 'reuseThreshold' => 3],
            ],
        ]);
    }

    /** An infeasible single-unit blueprint: 3 long slots, only 1 approved long question. */
    private function infeasibleSetup(): array
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create(['syllabus' => '# Data Structures\nTrees, graphs, hashing.']);
        $unit = Unit::factory()->for($subject)->create([
            'name' => 'Unit 1', 'position' => 1, 'content' => '## Trees\nBST, AVL, B-trees.',
        ]);
        Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'long', 'marks' => 10, 'status' => 'approved',
        ]);

        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section B', 'type' => 'Long Answer', 'count' => 3, 'marksEach' => 10],
        ], ['Unit 1'], 30);

        return [$teacher, $subject, $unit, $blueprint];
    }

    private function fakePython(int $count, string $type = 'long', int $marks = 10): void
    {
        $data = collect(range(1, $count))->map(fn ($i) => [
            'text' => "AI-authored {$type} question {$i} about trees.",
            'type' => $type,
            'marks' => $marks,
        ])->all();

        Http::fake(['*/generate-questions' => Http::response([
            'status' => 'success', 'data' => $data, 'errors' => [],
        ], 200)]);
    }

    public function test_expand_dispatches_a_batch_for_an_infeasible_blueprint(): void
    {
        Bus::fake();
        [$teacher, , $unit, $blueprint] = $this->infeasibleSetup();

        Sanctum::actingAs($teacher);

        $this->postJson("/api/blueprints/{$blueprint->id}/expand-bank")
            ->assertOk()
            ->assertJsonPath('satisfiable', false)
            ->assertJsonPath('slots.0.type', 'long')
            ->assertJsonPath('slots.0.unit_id', $unit->id);

        Bus::assertBatched(fn ($batch) => $batch->jobs->first() instanceof ExpandQuestionBank
            && $batch->name === "expand-bank:{$blueprint->id}:{$teacher->id}");
    }

    public function test_expand_returns_satisfiable_when_the_bank_is_already_sufficient(): void
    {
        Bus::fake();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        Question::factory()->count(3)->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'marks' => 5, 'status' => 'approved',
        ]);
        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 5],
        ], ['Unit 1'], 15);

        Sanctum::actingAs($teacher);

        $this->postJson("/api/blueprints/{$blueprint->id}/expand-bank")
            ->assertOk()
            ->assertJsonPath('satisfiable', true);

        Bus::assertNothingBatched();
    }

    public function test_expand_is_forbidden_for_another_owners_blueprint(): void
    {
        [, , , $blueprint] = $this->infeasibleSetup();

        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->postJson("/api/blueprints/{$blueprint->id}/expand-bank")->assertForbidden();
    }

    public function test_job_stores_approved_ai_questions_and_makes_the_blueprint_satisfiable(): void
    {
        [$teacher, $subject, $unit, $blueprint] = $this->infeasibleSetup();
        Sanctum::actingAs($teacher);

        // 1) Infeasible to start, and the shortfall names the target unit.
        $missing = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()
            ->assertJsonPath('satisfiable', false)
            ->assertJsonPath('missing_slots.0.unit_id', $unit->id)
            ->json('missing_slots');

        // 2) Run the job on the re-derived slots with Python faked.
        $this->fakePython(count: 4);
        $slots = array_map(fn ($m) => [
            'section_label' => $m['section_label'], 'type' => $m['type'],
            'marks' => $m['marks'], 'unit_id' => $m['unit_id'], 'need' => $m['need'],
        ], $missing);

        (new ExpandQuestionBank($blueprint->id, $slots))
            ->handle(app(PythonService::class), app(GroundingBuilder::class));

        // AI questions are approved, AI-sourced, and land on the resolved unit + marks.
        $ai = Question::where('source', 'ai')->get();
        $this->assertGreaterThanOrEqual(2, $ai->count());
        $this->assertTrue($ai->every(fn (Question $q) => $q->status === 'approved'
            && $q->unit_id === $unit->id && $q->marks === 10 && $q->type === 'long'));

        // 3) The same blueprint now generates a full paper.
        $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()
            ->assertJsonPath('satisfiable', true);
    }

    public function test_job_retries_until_the_deficit_is_filled_when_the_model_underdelivers(): void
    {
        [$teacher, $subject, $unit, $blueprint] = $this->infeasibleSetup();
        Sanctum::actingAs($teacher);

        $missing = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()->assertJsonPath('satisfiable', false)->json('missing_slots');

        // The deficit is 2 long questions, but the model under-delivers: 1 distinct
        // question on the first call, 2 more on the second. One call can't close it;
        // the retry loop must re-ask (for only the remaining need) until it does.
        Http::fakeSequence()
            ->push(['status' => 'success', 'errors' => [], 'data' => [
                ['text' => 'AI question A about AVL trees.', 'type' => 'long', 'marks' => 10],
            ]])
            ->push(['status' => 'success', 'errors' => [], 'data' => [
                ['text' => 'AI question B about B-trees.', 'type' => 'long', 'marks' => 10],
                ['text' => 'AI question C about red-black trees.', 'type' => 'long', 'marks' => 10],
            ]]);

        $slots = array_map(fn ($m) => [
            'section_label' => $m['section_label'], 'type' => $m['type'],
            'marks' => $m['marks'], 'unit_id' => $m['unit_id'], 'need' => $m['need'],
        ], $missing);

        (new ExpandQuestionBank($blueprint->id, $slots))
            ->handle(app(PythonService::class), app(GroundingBuilder::class));

        // Two rounds were needed; three distinct AI questions landed on the unit.
        Http::assertSentCount(2);
        $this->assertSame(3, Question::where('source', 'ai')->count());

        // One click's worth of expansion now makes the blueprint satisfiable.
        $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()->assertJsonPath('satisfiable', true);
    }

    public function test_job_dedups_repeated_text_and_stops_when_a_round_adds_nothing(): void
    {
        [, $subject, $unit, $blueprint] = $this->infeasibleSetup();

        // The model returns the *same* question every call — a common small-model failure.
        // Round 1 stores it once; round 2 adds nothing (all duplicates), so the loop must
        // bail rather than burn all MAX_ATTEMPTS_PER_SLOT calls.
        Http::fake(['*/generate-questions' => Http::response([
            'status' => 'success', 'errors' => [],
            'data' => [['text' => 'The one and only AVL question.', 'type' => 'long', 'marks' => 10]],
        ], 200)]);

        $slots = [[
            'section_label' => 'Section B', 'type' => 'long', 'marks' => 10,
            'unit_id' => $unit->id, 'need' => 2,
        ]];

        (new ExpandQuestionBank($blueprint->id, $slots))
            ->handle(app(PythonService::class), app(GroundingBuilder::class));

        // Only the single distinct question is stored, and we stopped after the empty round.
        $this->assertSame(1, Question::where('source', 'ai')->count());
        Http::assertSentCount(2);
    }

    public function test_job_isolates_malformed_items_and_stamps_slot_values(): void
    {
        [, $subject, $unit, $blueprint] = $this->infeasibleSetup();

        // Python already dropped the bad item; a survivor echoes a wrong type/marks,
        // which Laravel must override with the slot's own values (never trust the model).
        Http::fake(['*/generate-questions' => Http::response([
            'status' => 'success',
            'data' => [
                ['text' => 'Good one about AVL trees.', 'type' => 'long', 'marks' => 10],
                ['text' => 'Echoes a bad type/marks.', 'type' => 'short', 'marks' => 2],
            ],
            'errors' => ['item 2: text: field required'],
        ], 200)]);

        $slots = [[
            'section_label' => 'Section B', 'type' => 'long', 'marks' => 10,
            'unit_id' => $unit->id, 'need' => 2,
        ]];

        (new ExpandQuestionBank($blueprint->id, $slots))
            ->handle(app(PythonService::class), app(GroundingBuilder::class));

        // Both survivors stored, both stamped long/10 regardless of the echoed values.
        $ai = Question::where('source', 'ai')->get();
        $this->assertCount(2, $ai);
        $this->assertTrue($ai->every(fn (Question $q) => $q->type === 'long' && $q->marks === 10));
    }

    public function test_job_status_returns_404_for_an_unknown_batch(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->getJson('/api/jobs/does-not-exist')->assertNotFound();
    }

    public function test_expand_refuses_a_structurally_infeasible_blueprint(): void
    {
        Bus::fake();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();

        // 5 mandated units but only 3 question slots — coverage can never pass, so no
        // amount of AI bank expansion can help. The endpoint must not dispatch a job.
        $units = collect(range(1, 5))->map(fn ($n) => Unit::factory()->for($subject)->create([
            'name' => "Unit {$n}", 'position' => $n,
        ]));
        foreach ($units as $unit) {
            Question::factory()->count(2)->create([
                'subject_id' => $subject->id, 'unit_id' => $unit->id,
                'type' => 'long', 'marks' => 20, 'status' => 'approved',
            ]);
        }

        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section A', 'type' => 'Long Answer', 'count' => 3, 'marksEach' => 20],
        ], $units->pluck('name')->all(), 60);

        Sanctum::actingAs($teacher);

        $this->postJson("/api/blueprints/{$blueprint->id}/expand-bank")
            ->assertOk()
            ->assertJsonPath('satisfiable', false)
            ->assertJsonPath('expandable', false)
            ->assertJsonPath('jobId', null);

        Bus::assertNothingBatched();
    }

    public function test_generate_flags_a_structural_shortfall_as_not_expandable(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $units = collect(range(1, 5))->map(fn ($n) => Unit::factory()->for($subject)->create([
            'name' => "Unit {$n}", 'position' => $n,
        ]));
        foreach ($units as $unit) {
            Question::factory()->count(2)->create([
                'subject_id' => $subject->id, 'unit_id' => $unit->id,
                'type' => 'long', 'marks' => 20, 'status' => 'approved',
            ]);
        }
        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section A', 'type' => 'Long Answer', 'count' => 3, 'marksEach' => 20],
        ], $units->pluck('name')->all(), 60);

        Sanctum::actingAs($teacher);

        $response = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()
            ->assertJsonPath('satisfiable', false)
            ->assertJsonPath('expandable', false);

        $this->assertNotNull($response->json('shortfall_reason'));
    }
}
