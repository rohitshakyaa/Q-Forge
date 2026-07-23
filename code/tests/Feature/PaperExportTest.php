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

class PaperExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A subject with three units, each holding $perUnit approved short/4-mark
     * questions — deep enough for two disjoint single-section papers.
     *
     * @return array{0:User, 1:Subject, 2:Blueprint}
     */
    private function seedDeepBank(int $perUnit, int $lastNPapers): array
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $subject = Subject::factory()->create();
        $units = collect(['Unit 1', 'Unit 2', 'Unit 3'])->map(
            fn ($name, $i) => Unit::factory()->for($subject)->create(['name' => $name, 'position' => $i + 1])
        );

        foreach ($units as $unit) {
            Question::factory()->count($perUnit)->create([
                'subject_id' => $subject->id,
                'unit_id' => $unit->id,
                'type' => 'short',
                'marks' => 4,
                'status' => 'approved',
                'used_count' => 0,
            ]);
        }

        $blueprint = Blueprint::factory()->for($teacher, 'owner')->for($subject)->create([
            'total_marks' => 12,
            'definition' => [
                'sections' => [
                    ['id' => 1, 'name' => 'Section A', 'type' => 'Short Answer', 'count' => 3, 'marksEach' => 4, 'mandatory' => true],
                ],
                'unitRules' => ['Unit 1' => true, 'Unit 2' => true, 'Unit 3' => true],
                'unitAllocations' => [],
                'exclusionRules' => ['lastNPapers' => $lastNPapers, 'excludeExamYearsBack' => 0],
            ],
        ]);

        return [$teacher, $subject, $blueprint];
    }

    /** @return int[] persisted question ids for a paper */
    private function questionIds(Paper $paper): array
    {
        return $paper->paperQuestions()->pluck('question_id')->sort()->values()->all();
    }

    /**
     * Generate a preview then Save it — the two-step flow the UI uses. Generate
     * auto-persists a draft; Save promotes that draft to a kept paper.
     */
    private function generateAndSave(Blueprint $blueprint): Paper
    {
        $seed = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()->assertJsonPath('satisfiable', true)->json('seed');

        $id = $this->postJson('/api/papers', ['blueprint_id' => $blueprint->id, 'seed' => $seed])
            ->assertCreated()->json('paper.id');

        return Paper::findOrFail($id);
    }

    public function test_second_generation_excludes_questions_from_the_last_n_papers(): void
    {
        // 2 per unit = 6 short@4; one paper needs 3 → enough for exactly two disjoint papers.
        [$teacher, , $blueprint] = $this->seedDeepBank(perUnit: 2, lastNPapers: 1);
        Sanctum::actingAs($teacher);

        $this->generateAndSave($blueprint);
        $this->generateAndSave($blueprint);

        $papers = Paper::orderBy('id')->get();
        $this->assertCount(2, $papers);

        $first = $this->questionIds($papers[0]);
        $second = $this->questionIds($papers[1]);

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertEmpty(array_intersect($first, $second), 'Second paper reused a question from the first.');
    }

    public function test_export_returns_a_valid_pdf_and_marks_the_paper_exported(): void
    {
        [$teacher, , $blueprint] = $this->seedDeepBank(perUnit: 2, lastNPapers: 0);
        Sanctum::actingAs($teacher);

        $paper = $this->generateAndSave($blueprint);

        $response = $this->get("/api/papers/{$paper->id}/export?format=pdf");
        $response->assertOk();
        // dompdf download() returns a plain Response (not streamed).
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));

        $paper->refresh();
        $this->assertSame('exported', $paper->status);
        $this->assertSame(1, $paper->export_count);
    }

    public function test_export_returns_a_valid_docx_and_increments_export_count(): void
    {
        [$teacher, , $blueprint] = $this->seedDeepBank(perUnit: 2, lastNPapers: 0);
        Sanctum::actingAs($teacher);

        $paper = $this->generateAndSave($blueprint);

        $this->get("/api/papers/{$paper->id}/export?format=pdf")->assertOk();
        $response = $this->get("/api/papers/{$paper->id}/export?format=docx");
        $response->assertOk();
        // .docx is a zip archive — starts with the PK magic bytes.
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2));

        $this->assertSame(2, $paper->refresh()->export_count);
    }

    public function test_export_rejects_an_unsupported_format(): void
    {
        [$teacher, , $blueprint] = $this->seedDeepBank(perUnit: 2, lastNPapers: 0);
        Sanctum::actingAs($teacher);
        $paper = $this->generateAndSave($blueprint);

        $this->getJson("/api/papers/{$paper->id}/export?format=txt")->assertStatus(422);
    }

    public function test_history_is_owner_scoped_and_show_is_gated(): void
    {
        [$teacher, , $blueprint] = $this->seedDeepBank(perUnit: 2, lastNPapers: 0);
        Sanctum::actingAs($teacher);
        $paper = $this->generateAndSave($blueprint);

        $this->getJson('/api/papers')->assertOk()->assertJsonCount(1, 'data');

        $other = User::factory()->create(['role' => 'teacher']);
        Sanctum::actingAs($other);
        $this->getJson('/api/papers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/papers/{$paper->id}")->assertStatus(403);
    }

    public function test_analytics_reports_usage_aggregates(): void
    {
        [$teacher, , $blueprint] = $this->seedDeepBank(perUnit: 2, lastNPapers: 0);
        Sanctum::actingAs($teacher);
        $this->generateAndSave($blueprint);

        $this->getJson('/api/papers/analytics')
            ->assertOk()
            ->assertJsonPath('generated', 1)
            ->assertJsonPath('questionsUsed', 3)
            ->assertJsonPath('uniqueQuestions', 3)
            ->assertJsonPath('totalExports', 0);
    }
}
