<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function teacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }

    public function test_admin_can_create_subject_with_unique_code(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subjects', [
            'code' => 'CS302',
            'name' => 'Algorithms',
            'description' => 'Algo design',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'CS302');

        $this->assertDatabaseHas('subjects', ['code' => 'CS302']);

        // Duplicate code rejected.
        $this->postJson('/api/subjects', ['code' => 'CS302', 'name' => 'Dup'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_admin_can_update_and_delete_subject_by_code(): void
    {
        Sanctum::actingAs($this->admin());
        $subject = Subject::factory()->create(['code' => 'CS301']);

        $this->putJson('/api/subjects/CS301', ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->deleteJson('/api/subjects/CS301')->assertNoContent();
        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
    }

    public function test_teacher_can_read_but_not_write_subjects(): void
    {
        Subject::factory()->create(['code' => 'CS301']);
        Sanctum::actingAs($this->teacher());

        $this->getJson('/api/subjects')->assertOk()->assertJsonPath('data.0.code', 'CS301');
        $this->getJson('/api/subjects/CS301')->assertOk();

        $this->postJson('/api/subjects', ['code' => 'X1', 'name' => 'x'])->assertForbidden();
        $this->putJson('/api/subjects/CS301', ['name' => 'no'])->assertForbidden();
        $this->deleteJson('/api/subjects/CS301')->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/subjects')->assertUnauthorized();
    }

    public function test_subject_show_embeds_units_and_questions(): void
    {
        $subject = Subject::factory()->create(['code' => 'CS301']);
        $unit = $subject->units()->create(['name' => 'Trees', 'position' => 1]);
        $unit->questions()->create([
            'subject_id' => $subject->id,
            'type' => 'short', 'marks' => 4, 'text' => 'Q', 'source' => 'manual', 'status' => 'approved',
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/subjects/CS301')
            ->assertOk()
            ->assertJsonPath('data.units.0.name', 'Trees')
            ->assertJsonPath('data.units.0.questions.0.marks', 4);
    }
}
