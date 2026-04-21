<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@qforge.com')],
            [
                'name' => env('ADMIN_NAME', 'QForge Admin'),
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'teacher@qforge.com'],
            [
                'name' => 'QForge Teacher',
                'password' => Hash::make('password'),
                'role' => 'teacher',
            ]
        );
    }
}
