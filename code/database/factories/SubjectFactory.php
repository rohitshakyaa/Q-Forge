<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('??###')),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'syllabus' => '## '.Str::title(fake()->words(2, true))."\n\n- ".fake()->sentence(),
        ];
    }
}
