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
                'exclusionRules' => ['lastNPapers' => 0, 'reuseThreshold' => 3],
            ],
        ]);
    }

    public function test_generates_and_persists_a_draft_paper_without_bumping_used_count(): void
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

        $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()
            ->assertJsonPath('satisfiable', true)
            ->assertJsonPath('paper.status', 'draft')
            ->assertJsonCount(3, 'paper.sections.0.questions')
            ->assertJsonPath('constraint_results.0.pass', true);

        $this->assertSame(1, Paper::where('blueprint_id', $blueprint->id)->where('status', 'draft')->count());
        $this->assertSame(3, Paper::first()->paperQuestions()->count());
        // used_count stays 0 — repetition history only counts saved papers (M3).
        $this->assertSame(0, Question::where('used_count', '>', 0)->count());
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
            ->assertJsonPath('missing_slots.0.need', 3);

        $this->assertSame(0, Paper::count());
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
