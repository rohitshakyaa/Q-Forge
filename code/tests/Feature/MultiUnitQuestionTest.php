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

/**
 * Multi-unit questions: `questions.unit_id` stays the primary unit while the
 * `question_unit` pivot holds every unit the question touches. The generator
 * filters on any-overlap and counts coverage as the union of tagged units.
 */
class MultiUnitQuestionTest extends TestCase
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

    /** @return array{Subject, Unit, Unit, Unit} */
    private function subjectWithThreeUnits(): array
    {
        $subject = Subject::factory()->create(['code' => 'CS301']);
        $u1 = $subject->units()->create(['name' => 'Unit 1', 'position' => 1]);
        $u2 = $subject->units()->create(['name' => 'Unit 2', 'position' => 2]);
        $u3 = $subject->units()->create(['name' => 'Unit 3', 'position' => 3]);

        return [$subject, $u1, $u2, $u3];
    }

    // ── API ─────────────────────────────────────────────────────────────

    public function test_create_with_unit_ids_links_every_unit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, , $u2, $u3] = $this->subjectWithThreeUnits();

        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'unit_id' => $u2->id,
            'unit_ids' => [$u2->id, $u3->id],
            'type' => 'short',
            'marks' => 4,
            'text' => 'Compare traversal (Unit 2) with hashing (Unit 3).',
        ])->assertCreated()
            ->assertJsonPath('data.unit_id', $u2->id);

        $this->assertEqualsCanonicalizing(
            [$u2->id, $u3->id],
            $response->json('data.unit_ids'),
        );

        $question = Question::find($response->json('data.id'));
        $this->assertEqualsCanonicalizing(
            [$u2->id, $u3->id],
            $question->units()->pluck('units.id')->all(),
        );
    }

    public function test_create_without_unit_ids_links_only_the_primary(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, $u1] = $this->subjectWithThreeUnits();

        $response = $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'unit_id' => $u1->id,
            'type' => 'short',
            'marks' => 4,
            'text' => 'Define a BST.',
        ])->assertCreated();

        $question = Question::find($response->json('data.id'));
        $this->assertSame([$u1->id], $question->units()->pluck('units.id')->all());
    }

    public function test_unit_ids_must_include_the_primary_unit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, $u1, $u2, $u3] = $this->subjectWithThreeUnits();

        $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'unit_id' => $u1->id,
            'unit_ids' => [$u2->id, $u3->id],
            'type' => 'short',
            'marks' => 4,
            'text' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('unit_ids');
    }

    public function test_unit_ids_must_belong_to_the_subject(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, $u1] = $this->subjectWithThreeUnits();
        $foreign = Unit::factory()->create(); // different subject

        $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'unit_id' => $u1->id,
            'unit_ids' => [$u1->id, $foreign->id],
            'type' => 'short',
            'marks' => 4,
            'text' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('unit_ids');
    }

    public function test_index_unit_filter_matches_secondary_tags(): void
    {
        [$subject, $u1, $u2] = $this->subjectWithThreeUnits();
        $question = Question::factory()->for($subject)->for($u1)->create(['status' => 'approved']);
        $question->units()->syncWithoutDetaching([$u2->id]);
        Question::factory()->for($subject)->for($u1)->create(['status' => 'approved']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson("/api/questions?unit={$u2->id}&status=approved")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $question->id);
    }

    // ── Review ──────────────────────────────────────────────────────────

    public function test_approve_with_unit_ids_syncs_the_pivot(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, , $u2, $u3] = $this->subjectWithThreeUnits();
        $candidate = Question::factory()->pending()->for($subject)->create([
            'unit_id' => null,
            'marks' => null,
            'source' => 'extracted',
        ]);

        $this->postJson("/api/questions/{$candidate->id}/approve", [
            'unit_id' => $u2->id,
            'unit_ids' => [$u2->id, $u3->id],
            'marks' => 5,
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertEqualsCanonicalizing(
            [$u2->id, $u3->id],
            $candidate->fresh()->units()->pluck('units.id')->all(),
        );
    }

    public function test_approve_rejects_unit_ids_missing_the_primary(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, $u1, , $u3] = $this->subjectWithThreeUnits();
        $candidate = Question::factory()->pending()->for($subject)->create([
            'unit_id' => null,
            'marks' => null,
            'source' => 'extracted',
        ]);

        $this->postJson("/api/questions/{$candidate->id}/approve", [
            'unit_id' => $u1->id,
            'unit_ids' => [$u3->id],
            'marks' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('unit_ids');

        $this->assertSame('pending', $candidate->fresh()->status);
    }

    // ── Generation ──────────────────────────────────────────────────────

    /** @param  string[]  $allowedUnitNames */
    private function blueprintFor(User $owner, Subject $subject, int $count, array $allowedUnitNames): Blueprint
    {
        $unitRules = [];
        foreach ($allowedUnitNames as $name) {
            $unitRules[$name] = true;
        }

        return Blueprint::factory()->for($owner, 'owner')->for($subject)->create([
            'total_marks' => $count * 4,
            'definition' => [
                'sections' => [[
                    'id' => 1, 'name' => 'Section A', 'type' => 'Short Answer',
                    'count' => $count, 'marksEach' => 4, 'mandatory' => true,
                ]],
                'unitRules' => $unitRules,
                'unitAllocations' => [],
                'exclusionRules' => ['lastNPapers' => 0, 'reuseThreshold' => 3],
            ],
        ]);
    }

    public function test_multi_unit_question_qualifies_via_secondary_tag_and_snapshots_the_allowed_unit(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        [$subject, , $u2, $u3] = $this->subjectWithThreeUnits();

        // Primary = Unit 2 (excluded by the blueprint), also tagged Unit 3 (allowed).
        $question = Question::factory()->for($subject)->for($u2)->create([
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);
        $question->units()->syncWithoutDetaching([$u3->id]);

        $blueprint = $this->blueprintFor($teacher, $subject, 1, ['Unit 3']);

        Sanctum::actingAs($teacher);

        $this->generateAndSave($blueprint->id);

        // The paper never labels a question with a unit the blueprint excluded.
        $placed = Paper::first()->paperQuestions()->first();
        $this->assertSame($question->id, $placed->question_id);
        $this->assertSame($u3->id, $placed->unit_id);
    }

    public function test_one_multi_unit_question_covers_two_allowed_units(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        [$subject, $u1, $u2] = $this->subjectWithThreeUnits();

        // Two slots, units 1+2 allowed — but only Unit 1 questions exist, one of
        // which also covers Unit 2. Coverage must pass through the union rule.
        Question::factory()->for($subject)->for($u1)->create([
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);
        $spanning = Question::factory()->for($subject)->for($u1)->create([
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);
        $spanning->units()->syncWithoutDetaching([$u2->id]);

        $blueprint = $this->blueprintFor($teacher, $subject, 2, ['Unit 1', 'Unit 2']);

        Sanctum::actingAs($teacher);

        $response = $this->postJson('/api/papers/generate', ['blueprint_id' => $blueprint->id])
            ->assertOk()
            ->assertJsonPath('satisfiable', true);

        $coverage = collect($response->json('constraint_results'))
            ->firstWhere('label', 'Unit coverage');
        $this->assertTrue($coverage['pass']);
        $this->assertSame('2 units', $coverage['got']);
    }

    public function test_unrestricted_blueprint_snapshots_the_primary_unit(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        [$subject, , $u2, $u3] = $this->subjectWithThreeUnits();

        $question = Question::factory()->for($subject)->for($u2)->create([
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);
        $question->units()->syncWithoutDetaching([$u3->id]);

        $blueprint = $this->blueprintFor($teacher, $subject, 1, []);

        Sanctum::actingAs($teacher);

        $this->generateAndSave($blueprint->id);

        $this->assertSame($u2->id, Paper::first()->paperQuestions()->first()->unit_id);
    }

    // ── Invariant ───────────────────────────────────────────────────────

    public function test_factory_created_question_has_its_primary_unit_linked(): void
    {
        $question = Question::factory()->create();

        $this->assertSame(
            [$question->unit_id],
            $question->units()->pluck('units.id')->all(),
        );
    }
}
