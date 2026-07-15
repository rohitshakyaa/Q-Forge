<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
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

    public function test_admin_can_list_users(): void
    {
        Sanctum::actingAs($this->admin());
        $this->teacher();

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'created_at']]]);
    }

    public function test_teacher_cannot_reach_users_endpoint(): void
    {
        Sanctum::actingAs($this->teacher());

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_admin_can_create_a_user_with_initial_password(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', [
            'name' => 'New Teacher',
            'email' => 'new@qforge.com',
            'role' => 'teacher',
            'password' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'new@qforge.com')
            ->assertJsonPath('data.role', 'teacher');

        $this->assertDatabaseHas('users', ['email' => 'new@qforge.com', 'role' => 'teacher']);

        // The created account can immediately authenticate with that password.
        $this->postJson('/api/auth/login', [
            'email' => 'new@qforge.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.role', 'teacher');
    }

    public function test_create_requires_password_and_unique_email(): void
    {
        Sanctum::actingAs($this->admin());
        User::factory()->create(['email' => 'dup@qforge.com']);

        $this->postJson('/api/users', [
            'name' => 'No Password',
            'email' => 'dup@qforge.com',
            'role' => 'teacher',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password', 'email']);
    }

    public function test_admin_can_update_role_and_reset_password(): void
    {
        Sanctum::actingAs($this->admin());
        $user = User::factory()->create(['role' => 'teacher']);

        $this->putJson("/api/users/{$user->id}", [
            'role' => 'admin',
            'password' => 'brand-new-pass',
        ])->assertOk()->assertJsonPath('data.role', 'admin');

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_admin_can_delete_another_user(): void
    {
        Sanctum::actingAs($this->admin());
        $victim = $this->teacher();

        $this->deleteJson("/api/users/{$victim->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $victim->id]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_cannot_demote_the_last_admin(): void
    {
        $admin = $this->admin(); // the only admin
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", ['role' => 'teacher'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
    }

    public function test_can_demote_an_admin_when_another_admin_remains(): void
    {
        Sanctum::actingAs($this->admin());
        $second = User::factory()->create(['role' => 'admin']);

        $this->putJson("/api/users/{$second->id}", ['role' => 'teacher'])
            ->assertOk()
            ->assertJsonPath('data.role', 'teacher');
    }
}
