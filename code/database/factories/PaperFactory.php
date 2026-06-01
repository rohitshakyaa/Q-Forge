<?php

namespace Database\Factories;

use App\Models\Blueprint;
use App\Models\Paper;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paper>
 */
class PaperFactory extends Factory
{
    protected $model = Paper::class;

    public function definition(): array
    {
        $subject = Subject::factory();

        return [
            'owner_id' => User::factory()->teacher(),
            'blueprint_id' => Blueprint::factory()->for($subject),
            'subject_id' => $subject,
            'name' => fake()->words(3, true),
            'total_marks' => 50,
            'duration' => 90,
            'status' => 'draft',
            'export_count' => 0,
            'generated_at' => now(),
        ];
    }
}
