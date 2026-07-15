<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_logs_in_with_email_and_password_and_receives_teacher_role(): void
    {
        User::factory()->create([
            'email' => 'teacher@qforge.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'teacher@qforge.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);
    }

    public function test_admin_logs_in_with_email_and_password_and_receives_admin_role(): void
    {
        User::factory()->create([
            'email' => 'admin@qforge.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@qforge.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_login_does_not_accept_or_require_a_role_from_the_client(): void
    {
        User::factory()->create([
            'email' => 'admin@qforge.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // A client-supplied role is ignored: the real (admin) role is returned
        // even when the client claims 'teacher'.
        $this->postJson('/api/auth/login', [
            'email' => 'admin@qforge.com',
            'password' => 'password',
            'role' => 'teacher',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_wrong_password_is_rejected_with_422(): void
    {
        User::factory()->create([
            'email' => 'teacher@qforge.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'teacher@qforge.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }
}
