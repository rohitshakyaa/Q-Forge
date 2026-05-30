<?php

namespace Tests\Feature;

use App\Models\Blueprint;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlueprintApiTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(string $code = 'CS301'): array
    {
        return [
            'subject' => $code,
            'name' => 'Standard Midterm',
            'total_marks' => 50,
            'duration' => 90,
            'ai_assist' => true,
            'definition' => [
                'sections' => [['type' => 'Short Answer', 'count' => 5, 'marksEach' => 4]],
                'unitRules' => ['Trees' => true],
                'unitAllocations' => ['Trees' => [['marks' => 4, 'count' => 5]]],
                'exclusionRules' => ['lastNPapers' => 2, 'reuseThreshold' => 3],
            ],
        ];
    }

    public function test_teacher_creates_blueprint_owned_and_subject_resolved(): void
    {
        $subject = Subject::factory()->create(['code' => 'CS301']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/blueprints', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.owner_id', $teacher->id)
            ->assertJsonPath('data.subject_id', $subject->id)
            ->assertJsonPath('data.subject_code', 'CS301');
    }

    public function test_index_only_returns_own_blueprints(): void
    {
        $subject = Subject::factory()->create();
        $me = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        Blueprint::factory()->for($subject)->create(['owner_id' => $me->id, 'name' => 'Mine']);
        Blueprint::factory()->for($subject)->create(['owner_id' => $other->id, 'name' => 'Theirs']);

        Sanctum::actingAs($me);

        $this->getJson('/api/blueprints')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_cannot_touch_another_teachers_blueprint(): void
    {
        $subject = Subject::factory()->create();
        $other = User::factory()->create(['role' => 'teacher']);
        $bp = Blueprint::factory()->for($subject)->create(['owner_id' => $other->id]);

        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->getJson("/api/blueprints/{$bp->id}")->assertForbidden();
        $this->putJson("/api/blueprints/{$bp->id}", $this->validPayload())->assertForbidden();
        $this->deleteJson("/api/blueprints/{$bp->id}")->assertForbidden();
    }

    public function test_definition_requires_expected_keys(): void
    {
        Subject::factory()->create(['code' => 'CS301']);
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $payload = $this->validPayload();
        unset($payload['definition']['unitRules']);

        $this->postJson('/api/blueprints', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('definition.unitRules');
    }

    public function test_admin_cannot_access_blueprints(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/blueprints')->assertForbidden();
    }
}
