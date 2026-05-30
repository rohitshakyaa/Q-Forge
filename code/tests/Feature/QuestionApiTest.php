<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionApiTest extends TestCase
{
    use RefreshDatabase;

    private function subjectWithUnit(): array
    {
        $subject = Subject::factory()->create(['code' => 'CS301']);
        $unit = $subject->units()->create(['name' => 'Trees', 'position' => 1]);

        return [$subject, $unit];
    }

    public function test_admin_create_defaults_to_manual_approved(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, $unit] = $this->subjectWithUnit();

        $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'unit_id' => $unit->id,
            'type' => 'short',
            'marks' => 4,
            'text' => 'Define a BST.',
        ])->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.used_count', 0)
            ->assertJsonPath('data.subject_code', 'CS301')
            ->assertJsonPath('data.unit_name', 'Trees');
    }

    public function test_unit_must_belong_to_subject(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject] = $this->subjectWithUnit();
        $otherUnit = Unit::factory()->create(); // belongs to a different subject

        $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'unit_id' => $otherUnit->id,
            'type' => 'short',
            'marks' => 4,
            'text' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('unit_id');
    }

    public function test_invalid_type_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        [$subject, $unit] = $this->subjectWithUnit();

        $this->postJson('/api/questions', [
            'subject_id' => $subject->id,
            'unit_id' => $unit->id,
            'type' => 'essay',
            'marks' => 4,
            'text' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_index_filters_and_paginates(): void
    {
        [$subject, $unit] = $this->subjectWithUnit();
        Question::factory()->count(3)->for($subject)->for($unit)->create(['type' => 'long', 'status' => 'approved']);
        Question::factory()->count(2)->for($subject)->for($unit)->create(['type' => 'mcq', 'status' => 'approved']);
        Question::factory()->for($subject)->for($unit)->create(['type' => 'long', 'status' => 'pending']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/questions?subject=CS301&type=long&status=approved')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/questions?per_page=2&status=approved')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_teacher_cannot_access_questions(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));
        $this->getJson('/api/questions')->assertForbidden();
        $this->postJson('/api/questions', [])->assertForbidden();
    }
}
