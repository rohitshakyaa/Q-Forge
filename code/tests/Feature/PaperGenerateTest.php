<?php

namespace Tests\Feature;

use App\Models\Blueprint;
use App\Models\Paper;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaperGenerateTest extends TestCase
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
                'exclusionRules' => ['lastNPapers' => 0, 'excludeExamYearsBack' => 0],
            ],
        ]);
    }

    public function test_generate_auto_persists_a_draft_without_bumping_usage(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $units = collect(['Unit 1', 'Unit 2', 'Unit 3'])->map(
            fn ($name, $i) => Unit::factory()->for($subject)->create(['name' => $name, 'position' => $i + 1])
        );
        foreach ($units as $unit) {
            Question::factory()->count(2)->create([
                'subject_id' => $subject->id, 'unit_id' => $unit->id,
                'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
            ]);
        }

        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2', 'Unit 3'], 12);

        Sanctum::actingAs($teacher);

        $id = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()
            ->assertJsonPath('satisfiable', true)
            // Auto-persisted as a draft: a real id + status 'draft', with seed +
            // blueprint echoed so Save can promote it.
            ->assertJsonPath('paper.status', 'draft')
            ->assertJsonPath('blueprint_id', $blueprint->id)
            ->assertJsonStructure(['seed'])
            ->assertJsonCount(3, 'paper.sections.0.questions')
            ->assertJsonPath('constraint_results.0.pass', true)
            ->json('paper.id');

        $this->assertNotNull($id);

        // Exactly one draft persisted; a draft is not "used", so usage counters and
        // the blueprint's last_used_at stay untouched until an explicit Save.
        $this->assertSame(1, Paper::count());
        $this->assertSame('draft', Paper::sole()->status);
        $this->assertSame(0, Question::where('used_count', '>', 0)->count());
        $this->assertNull($blueprint->fresh()->last_used_at);
    }

    public function test_regenerating_replaces_the_previous_draft(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $units = collect(['Unit 1', 'Unit 2', 'Unit 3'])->map(
            fn ($name, $i) => Unit::factory()->for($subject)->create(['name' => $name, 'position' => $i + 1])
        );
        foreach ($units as $unit) {
            Question::factory()->count(2)->create([
                'subject_id' => $subject->id, 'unit_id' => $unit->id,
                'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
            ]);
        }

        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2', 'Unit 3'], 12);

        Sanctum::actingAs($teacher);

        $first = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()->json('paper.id');
        $second = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()->json('paper.id');

        // One live draft per blueprint — the second generate replaced the first.
        $this->assertNotSame($first, $second);
        $this->assertSame(1, Paper::count());
        $this->assertNull(Paper::find($first));
    }

    public function test_saving_a_preview_persists_it_and_bumps_used_count(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $units = collect(['Unit 1', 'Unit 2', 'Unit 3'])->map(
            fn ($name, $i) => Unit::factory()->for($subject)->create(['name' => $name, 'position' => $i + 1])
        );
        foreach ($units as $unit) {
            Question::factory()->count(2)->create([
                'subject_id' => $subject->id, 'unit_id' => $unit->id,
                'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
            ]);
        }

        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4],
        ], ['Unit 1', 'Unit 2', 'Unit 3'], 12);

        Sanctum::actingAs($teacher);

        // Preview first to obtain the seed.
        $seed = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()->json('seed');

        // Save: re-generates deterministically from the seed and persists.
        $this->postJson('/api/papers', [
            'blueprint_id' => $blueprint->id,
            'seed' => $seed,
            'name' => 'Midterm A',
        ])
            ->assertCreated()
            ->assertJsonPath('paper.status', 'saved')
            ->assertJsonPath('paper.name', 'Midterm A');

        $paper = Paper::sole();
        $this->assertSame('saved', $paper->status);
        $this->assertSame(3, $paper->paperQuestions()->count());
        // used_count + last_used_at are bumped here — only on an explicit Save.
        $this->assertSame(3, Question::where('used_count', '>', 0)->count());
        $this->assertNotNull($blueprint->fresh()->last_used_at);
    }

    public function test_saving_refuses_when_the_bank_can_no_longer_satisfy_the_seed(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        Question::factory()->count(1)->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'marks' => 4, 'status' => 'approved',
        ]);

        // Blueprint needs 2 questions but the bank has 1 → infeasible.
        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 2, 'marksEach' => 4],
        ], ['Unit 1'], 8);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/papers', ['blueprint_id' => $blueprint->id, 'seed' => 123])
            ->assertStatus(422);

        $this->assertSame(0, Paper::count());
    }

    public function test_returns_missing_slots_and_persists_nothing_for_infeasible_blueprint(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        Question::factory()->count(2)->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'long', 'marks' => 10, 'status' => 'approved',
        ]);

        $blueprint = $this->blueprintFor($teacher, $subject, [
            ['name' => 'Section B', 'type' => 'Long Answer', 'count' => 5, 'marksEach' => 10],
        ], ['Unit 1'], 50);

        Sanctum::actingAs($teacher);

        $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()
            ->assertJsonPath('satisfiable', false)
            ->assertJsonPath('missing_slots.0.type', 'long')
            ->assertJsonPath('missing_slots.0.need', 3)
            // Both the back-compat single id and the new target set are exposed.
            ->assertJsonPath('missing_slots.0.unit_id', $unit->id)
            ->assertJsonPath('missing_slots.0.unit_ids', [$unit->id]);

        $this->assertSame(0, Paper::count());
        // No paper persisted means the blueprint was not "used".
        $this->assertNull($blueprint->fresh()->last_used_at);
    }

    public function test_admin_cannot_generate_papers(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/papers/generate', ['blueprint_id' => 1])
            ->assertForbidden();
    }

    public function test_cannot_generate_from_another_teachers_blueprint(): void
    {
        $other = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $blueprint = $this->blueprintFor($other, $subject, [
            ['name' => 'Section A', 'type' => 'Short Answer', 'count' => 1, 'marksEach' => 4],
        ], [], 4);

        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertForbidden();
    }
}
