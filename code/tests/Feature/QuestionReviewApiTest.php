<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->subject = Subject::factory()->create(['code' => 'CS301']);
        $this->unit = $this->subject->units()->create(['name' => 'Sorting', 'position' => 1]);
    }

    private function candidate(array $overrides = []): Question
    {
        return Question::create(array_merge([
            'subject_id' => $this->subject->id,
            'unit_id' => $this->unit->id,
            'type' => 'short',
            'marks' => 5,
            'text' => 'Define a hash collision.',
            'source' => 'extracted',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_pending_candidates_are_listed_for_review(): void
    {
        $pending = $this->candidate();
        $this->candidate(['status' => 'approved', 'text' => 'Already in the bank.']);

        $this->getJson('/api/questions?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);
    }

    public function test_approving_flips_the_status_to_approved(): void
    {
        $question = $this->candidate();

        $this->postJson("/api/questions/{$question->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $question->refresh()->status);
    }

    public function test_approving_can_correct_the_candidate_in_place(): void
    {
        $second = $this->subject->units()->create(['name' => 'Hashing', 'position' => 2]);
        $question = $this->candidate();

        $this->postJson("/api/questions/{$question->id}/approve", [
            'unit_id' => $second->id,
            'marks' => 10,
            'type' => 'long',
            'text' => 'Explain hash collisions and two resolution strategies.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $question->refresh();
        $this->assertSame($second->id, $question->unit_id);
        $this->assertSame(10, $question->marks);
        $this->assertSame('long', $question->type);
        $this->assertStringContainsString('resolution strategies', $question->text);
    }

    public function test_an_unlinked_candidate_cannot_be_approved_without_a_unit(): void
    {
        $question = $this->candidate(['unit_id' => null]);

        $this->postJson("/api/questions/{$question->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');

        $this->assertSame('pending', $question->refresh()->status);
    }

    public function test_supplying_the_missing_unit_lets_the_candidate_through(): void
    {
        $question = $this->candidate(['unit_id' => null]);

        $this->postJson("/api/questions/{$question->id}/approve", ['unit_id' => $this->unit->id])
            ->assertOk();

        $this->assertSame('approved', $question->refresh()->status);
    }

    public function test_approving_with_an_explicit_unit_clears_the_auto_assign_flag(): void
    {
        $second = $this->subject->units()->create(['name' => 'Hashing', 'position' => 2]);
        $question = $this->candidate([
            'attributes' => ['unit_auto_assigned' => ['unit_id' => $this->unit->id, 'score' => 0.71]],
        ]);

        $this->postJson("/api/questions/{$question->id}/approve", ['unit_id' => $second->id])
            ->assertOk();

        // The human picked a unit — the machine-tag provenance must not survive.
        $this->assertArrayNotHasKey('unit_auto_assigned', $question->refresh()->attributes);
    }

    public function test_approving_without_touching_the_unit_keeps_the_auto_assign_flag(): void
    {
        $question = $this->candidate([
            'attributes' => ['unit_auto_assigned' => ['unit_id' => $this->unit->id, 'score' => 0.71]],
        ]);

        $this->postJson("/api/questions/{$question->id}/approve")->assertOk();

        // Approved as-is: keep the provenance ("approved with an AI tag").
        $this->assertSame(
            ['unit_id' => $this->unit->id, 'score' => 0.71],
            $question->refresh()->attributes['unit_auto_assigned'],
        );
    }

    public function test_editing_the_unit_clears_the_auto_assign_flag(): void
    {
        $second = $this->subject->units()->create(['name' => 'Hashing', 'position' => 2]);
        $question = $this->candidate([
            'attributes' => ['unit_auto_assigned' => ['unit_id' => $this->unit->id, 'score' => 0.71]],
        ]);

        $this->putJson("/api/questions/{$question->id}", ['unit_id' => $second->id])
            ->assertOk();

        $question->refresh();
        $this->assertSame($second->id, $question->unit_id);
        $this->assertArrayNotHasKey('unit_auto_assigned', $question->attributes);
    }

    public function test_a_candidate_without_marks_cannot_be_approved(): void
    {
        $question = $this->candidate(['marks' => null]);

        $this->postJson("/api/questions/{$question->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('marks');
    }

    public function test_the_unit_must_belong_to_the_questions_subject(): void
    {
        $foreign = Subject::factory()->create(['code' => 'CS999']);
        $foreignUnit = $foreign->units()->create(['name' => 'Elsewhere', 'position' => 1]);
        $question = $this->candidate();

        $this->postJson("/api/questions/{$question->id}/approve", ['unit_id' => $foreignUnit->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');

        $this->assertSame('pending', $question->refresh()->status);
    }

    public function test_rejecting_flips_the_status_to_rejected(): void
    {
        $question = $this->candidate();

        $this->postJson("/api/questions/{$question->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_bulk_approve_takes_the_complete_and_reports_the_rest(): void
    {
        $ready = $this->candidate();
        $unlinked = $this->candidate(['unit_id' => null, 'text' => 'Unlinked question.']);
        $unmarked = $this->candidate(['marks' => null, 'text' => 'Unmarked question.']);

        $this->postJson('/api/questions/bulk-approve', [
            'ids' => [$ready->id, $unlinked->id, $unmarked->id],
        ])->assertOk()
            ->assertJsonPath('approved', [$ready->id])
            ->assertJsonPath('skipped.0.id', $unlinked->id)
            ->assertJsonPath('skipped.0.reason', 'missing unit')
            ->assertJsonPath('skipped.1.id', $unmarked->id)
            ->assertJsonPath('skipped.1.reason', 'missing marks');

        $this->assertSame('approved', $ready->refresh()->status);
        $this->assertSame('pending', $unlinked->refresh()->status);
    }

    public function test_bulk_approve_excludes_exact_duplicates(): void
    {
        $ready = $this->candidate();
        $duplicate = $this->candidate([
            'text' => 'Define a hash collision, again.',
            'attributes' => ['duplicate_of' => [['question_id' => 999, 'status' => 'approved']]],
        ]);

        $this->postJson('/api/questions/bulk-approve', [
            'ids' => [$ready->id, $duplicate->id],
        ])->assertOk()
            ->assertJsonPath('approved', [$ready->id])
            ->assertJsonPath('skipped.0.id', $duplicate->id)
            ->assertJsonPath('skipped.0.reason', 'duplicate');

        // The duplicate is untouched — it needs an explicit, per-item decision.
        $this->assertSame('pending', $duplicate->refresh()->status);
    }

    public function test_bulk_reject_rejects_every_named_candidate(): void
    {
        $first = $this->candidate();
        $second = $this->candidate(['unit_id' => null, 'text' => 'Another one.']);

        $this->postJson('/api/questions/bulk-reject', ['ids' => [$first->id, $second->id]])
            ->assertOk();

        $this->assertSame('rejected', $first->refresh()->status);
        $this->assertSame('rejected', $second->refresh()->status);
    }

    public function test_bulk_endpoints_are_not_shadowed_by_the_show_route(): void
    {
        // `questions/bulk-approve` must not be parsed as `questions/{question}`.
        $this->postJson('/api/questions/bulk-approve', ['ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    public function test_approved_extracted_questions_become_eligible_for_generation(): void
    {
        $question = $this->candidate();

        $this->postJson("/api/questions/{$question->id}/approve")->assertOk();

        // This is the exact shape the paper generator selects on.
        $eligible = Question::where('subject_id', $this->subject->id)
            ->where('status', 'approved')
            ->whereNotNull('unit_id')
            ->whereNotNull('marks')
            ->pluck('id');

        $this->assertTrue($eligible->contains($question->id));
    }

    public function test_teachers_cannot_review_the_queue(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));
        $question = $this->candidate();

        $this->postJson("/api/questions/{$question->id}/approve")->assertForbidden();
        $this->postJson("/api/questions/{$question->id}/reject")->assertForbidden();
        $this->postJson('/api/questions/bulk-approve', ['ids' => [$question->id]])->assertForbidden();
    }
}
