<?php

namespace Database\Factories;

use App\Models\Paper;
use App\Models\PaperQuestion;
use App\Models\Question;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaperQuestion>
 */
class PaperQuestionFactory extends Factory
{
    protected $model = PaperQuestion::class;

    public function definition(): array
    {
        return [
            'paper_id' => Paper::factory(),
            'question_id' => Question::factory(),
            'unit_id' => Unit::factory(),
            'section_label' => 'Section A',
            'display_no' => fake()->numberBetween(1, 10),
            'marks' => fake()->randomElement([2, 4, 5, 10]),
            'is_ai' => false,
        ];
    }
}
