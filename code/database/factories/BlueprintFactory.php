<?php

namespace Database\Factories;

use App\Models\Blueprint;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Blueprint>
 */
class BlueprintFactory extends Factory
{
    protected $model = Blueprint::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->state(['role' => 'teacher']),
            'subject_id' => Subject::factory(),
            'name' => fake()->words(2, true).' Exam',
            'total_marks' => 50,
            'duration' => 90,
            'ai_assist' => false,
            'definition' => [
                'sections' => [
                    ['id' => 1, 'name' => 'Section A — Short Answer', 'type' => 'Short Answer', 'count' => 5, 'marksEach' => 4, 'mandatory' => true],
                    ['id' => 2, 'name' => 'Section B — Long Answer', 'type' => 'Long Answer', 'count' => 3, 'marksEach' => 10, 'mandatory' => false],
                ],
                'unitRules' => ['Unit 1' => true, 'Unit 2' => true, 'Unit 3' => true],
                'unitAllocations' => [
                    'Unit 1' => [['marks' => 4, 'count' => 2], ['marks' => 10, 'count' => 1]],
                ],
                'exclusionRules' => ['lastNPapers' => 2, 'excludeExamYearsBack' => 0],
            ],
            'last_used_at' => null,
        ];
    }
}
