<?php

namespace Database\Factories;

use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'name' => 'Unit '.fake()->unique()->numberBetween(1, 50),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
