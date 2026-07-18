<?php

namespace Tests\Feature;

use App\Models\Blueprint;
use App\Models\Paper;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Services\ImportedPaperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PastPaperApiTest extends TestCase
{
    use RefreshDatabase;

    /** Generate a preview then Save it (generate no longer persists on its own). */
    private function generateAndSave(int $blueprintId): void
    {
        $seed = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprintId])
            ->assertOk()->assertJsonPath('satisfiable', true)->json('seed');
        $this->postJson('/api/papers', ['blueprint_id' => $blueprintId, 'seed' => $seed])
            ->assertCreated();
    }

    /** A teacher-owned blueprint for one short/4 section over $unitName, with the cross-paper window on. */
    private function blueprintFor(User $owner, Subject $subject, string $unitName, int $count, int $lastN): Blueprint
    {
        return Blueprint::factory()->for($owner, 'owner')->for($subject)->create([
            'total_marks' => $count * 4,
            'definition' => [
                'sections' => [[
                    'id' => 1, 'name' => 'Section A', 'type' => 'Short Answer',
                    'count' => $count, 'marksEach' => 4, 'mandatory' => true,
                ]],
                'unitRules' => [$unitName => true],
                'unitAllocations' => [],
                'exclusionRules' => ['lastNPapers' => $lastN, 'reuseThreshold' => 99],
            ],
        ]);
    }

    /** Question ids in the teacher's most recent generated paper. */
    private function generatedQuestionIds(User $teacher): array
    {
        $paper = Paper::where('owner_id', $teacher->id)->where('origin', 'generated')
            ->orderByDesc('id')->firstOrFail();

        return $paper->paperQuestions()->pluck('question_id')->all();
    }

    public function test_admin_can_record_a_past_paper_with_mixed_existing_and_new_questions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $existing = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'marks' => 4, 'status' => 'approved',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/subjects/{$subject->code}/past-papers", [
            'name' => 'CSC409 Final 2024',
            'exam_date' => '2024-12-15',
            'question_ids' => [$existing->id],
            'new_questions' => [['text' => 'Q new', 'type' => 'long', 'marks' => 10, 'unit_id' => $unit->id]],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('paper.status', 'saved')
            ->assertJsonPath('paper.marks', 14);

        $this->assertDatabaseHas('papers', [
            'name' => 'CSC409 Final 2024', 'origin' => 'imported',
            'blueprint_id' => null, 'owner_id' => $admin->id,
        ]);
    }

    public function test_teacher_cannot_record_a_past_paper(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        Sanctum::actingAs($teacher);

        $this->postJson("/api/subjects/{$subject->code}/past-papers", [
            'name' => 'X', 'exam_date' => '2024-01-01', 'question_ids' => [],
        ])->assertStatus(403);
    }

    public function test_rejects_an_empty_past_paper(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = Subject::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/subjects/{$subject->code}/past-papers", [
            'name' => 'Empty', 'exam_date' => '2024-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('question_ids');
    }

    public function test_rejects_questions_from_another_subject(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = Subject::factory()->create();
        $other = Subject::factory()->create();
        $otherUnit = Unit::factory()->for($other)->create(['name' => 'U', 'position' => 1]);
        $foreign = Question::factory()->create([
            'subject_id' => $other->id, 'unit_id' => $otherUnit->id,
            'type' => 'short', 'marks' => 4, 'status' => 'approved',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/subjects/{$subject->code}/past-papers", [
            'name' => 'X', 'exam_date' => '2024-01-01', 'question_ids' => [$foreign->id],
        ])->assertStatus(422)->assertJsonValidationErrors('question_ids');
    }

    public function test_rejects_new_question_unit_from_another_subject(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = Subject::factory()->create();
        $other = Subject::factory()->create();
        $foreignUnit = Unit::factory()->for($other)->create(['name' => 'U', 'position' => 1]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/subjects/{$subject->code}/past-papers", [
            'name' => 'X', 'exam_date' => '2024-01-01',
            'new_questions' => [['text' => 'Q', 'type' => 'short', 'marks' => 4, 'unit_id' => $foreignUnit->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('new_questions.0.unit_id');
    }

    public function test_imported_exam_excludes_questions_for_every_teacher_of_the_subject(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacherA = User::factory()->create(['role' => 'teacher']);
        $teacherB = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        // Four short/4 questions; the imported exam claims the first one.
        $questions = Question::factory()->count(4)->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);
        $importedId = $questions->first()->id;

        (new ImportedPaperService)->record(
            $subject, 'Real 2024', Carbon::parse('2024-12-15'), [$importedId], [], $admin,
        );

        // Each teacher generates 3 short/4 with the window on; neither may reuse the imported question.
        foreach ([$teacherA, $teacherB] as $teacher) {
            $blueprint = $this->blueprintFor($teacher, $subject, 'Unit 1', 3, 1);
            Sanctum::actingAs($teacher);
            $this->generateAndSave($blueprint->id);

            $this->assertNotContains($importedId, $this->generatedQuestionIds($teacher),
                'A generated paper reused a question from an imported past exam.');
        }
    }

    public function test_imported_papers_are_absent_from_teacher_history_and_analytics(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();

        // A teacher-owned imported paper must still be hidden by the origin filter.
        $imported = Paper::factory()->imported()->create([
            'owner_id' => $teacher->id, 'subject_id' => $subject->id, 'name' => 'Hidden import',
        ]);
        $generated = Paper::factory()->create([
            'owner_id' => $teacher->id, 'subject_id' => $subject->id, 'origin' => 'generated',
        ]);

        Sanctum::actingAs($teacher);

        $history = $this->getJson('/api/papers')->assertOk()->json('data');
        $ids = collect($history)->pluck('id')->all();
        $this->assertContains($generated->id, $ids);
        $this->assertNotContains($imported->id, $ids);

        $this->getJson('/api/papers/analytics')->assertOk()->assertJsonPath('generated', 1);
    }
}
