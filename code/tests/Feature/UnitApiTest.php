<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_nested_unit_and_position_defaults(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $subject = Subject::factory()->create(['code' => 'CS301']);

        $this->postJson('/api/subjects/CS301/units', ['name' => 'Unit A'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Unit A')
            ->assertJsonPath('data.position', 1);

        $this->postJson('/api/subjects/CS301/units', ['name' => 'Unit B'])
            ->assertJsonPath('data.position', 2);

        $this->assertDatabaseCount('units', 2);
    }

    public function test_admin_can_update_and_delete_unit_shallow(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $subject = Subject::factory()->create();
        $unit = $subject->units()->create(['name' => 'Old', 'position' => 1]);

        $this->putJson("/api/units/{$unit->id}", ['name' => 'New'])
            ->assertOk()->assertJsonPath('data.name', 'New');

        $this->deleteJson("/api/units/{$unit->id}")->assertNoContent();
        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }

    public function test_teacher_can_list_units_but_not_write(): void
    {
        $subject = Subject::factory()->create(['code' => 'CS301']);
        $subject->units()->create(['name' => 'U1', 'position' => 1]);
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->getJson('/api/subjects/CS301/units')->assertOk()->assertJsonPath('data.0.name', 'U1');
        $this->postJson('/api/subjects/CS301/units', ['name' => 'Nope'])->assertForbidden();
    }
}
