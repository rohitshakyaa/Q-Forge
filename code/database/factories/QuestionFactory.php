<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        $subject = Subject::factory();

        return [
            'subject_id' => $subject,
            'unit_id' => Unit::factory()->for($subject),
            'type' => fake()->randomElement(['short', 'long', 'mcq']),
            'marks' => fake()->randomElement([2, 4, 5, 10]),
            'difficulty' => fake()->randomElement(['easy', 'medium', 'hard']),
            'text' => fake()->sentence(),
            'source' => 'manual',
            'status' => 'approved',
            'attributes' => null,
            'used_count' => 0,
        ];
    }

    /**
     * Uphold the model invariant: the primary unit is always linked in the
     * question_unit pivot (tests may then attach extra units on top).
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Question $question) => $question->syncUnitLinks());
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }
}
