<?php

namespace Database\Seeders;

use App\Models\Blueprint;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Two demo blueprints on CSC409 Advanced Java (bank seeded by TuPastPaperSeeder):
 *
 *  - "TU Final Exam" — satisfiable straight from the bank, so the deterministic
 *    engine returns a paper instantly (and auto-saves it as a draft).
 *  - "JavaFX Short-Answer Set (needs AI)" — restricted to the thin "GUI with
 *    JavaFX" unit and asks for six 5-mark short answers, but the bank holds only
 *    three. The shortfall is an ordinary (non-structural) bank deficit, so
 *    generation reports satisfiable=false, expandable=true — the "Expand with AI"
 *    path. No MCQ anywhere: the whole demo bank is long/10 + short/5 only.
 *
 * Depends on AdminUserSeeder (teacher) and TuPastPaperSeeder (CSC409 + units).
 * Idempotent: both blueprints are updateOrCreate'd by (owner, name).
 */
class DemoBlueprintSeeder extends Seeder
{
    public function run(): void
    {
        $teacher = User::where('email', 'teacher@qforge.com')->first();
        $subject = Subject::where('code', 'CSC409')->first();

        if (! $teacher || ! $subject) {
            $this->command?->warn('DemoBlueprintSeeder skipped: teacher or CSC409 not found (run AdminUserSeeder + TuPastPaperSeeder first).');

            return;
        }

        $this->seedSatisfiableBlueprint($teacher, $subject);
        $this->seedExpansionBlueprint($teacher, $subject);

        $this->command?->info('Seeded CSC409 demo blueprints (satisfiable + AI-expansion).');
    }

    /** A blueprint the bank satisfies immediately: 2× long/10 + 8× short/5 = 60. */
    private function seedSatisfiableBlueprint(User $teacher, Subject $subject): void
    {
        Blueprint::updateOrCreate(
            ['owner_id' => $teacher->id, 'name' => 'TU Final Exam'],
            [
                'subject_id' => $subject->id,
                'total_marks' => 60,
                'duration' => 180,
                'ai_assist' => false,
                'definition' => [
                    'sections' => [
                        ['id' => 1, 'name' => 'Section A — Long Answer', 'type' => 'Long Answer', 'count' => 2, 'marksEach' => 10, 'mandatory' => true],
                        ['id' => 2, 'name' => 'Section B — Short Answer', 'type' => 'Short Answer', 'count' => 8, 'marksEach' => 5, 'mandatory' => true],
                    ],
                    'unitRules' => [
                        'Programming in Java' => true,
                        'User Interface Components with Swing' => true,
                        'Database Connectivity' => true,
                        'Servlets and Java Server Pages' => true,
                        'RMI and CORBA' => true,
                    ],
                    'unitAllocations' => [],
                    'exclusionRules' => ['lastNPapers' => 2, 'excludeExamYearsBack' => 0],
                ],
                'last_used_at' => null,
            ]
        );
    }

    /**
     * Needs AI expansion: six 5-mark short answers restricted to "GUI with
     * JavaFX", which the bank has only three of. Single allowed unit ≤ 2× slots
     * and uncapped, so it's an ordinary bank deficit (expandable), not structural.
     */
    private function seedExpansionBlueprint(User $teacher, Subject $subject): void
    {
        Blueprint::updateOrCreate(
            ['owner_id' => $teacher->id, 'name' => 'JavaFX Short-Answer Set (needs AI)'],
            [
                'subject_id' => $subject->id,
                'total_marks' => 30,
                'duration' => 90,
                'ai_assist' => true,
                'definition' => [
                    'sections' => [
                        ['id' => 1, 'name' => 'Section A — Short Answer', 'type' => 'Short Answer', 'count' => 6, 'marksEach' => 5, 'mandatory' => true],
                    ],
                    'unitRules' => [
                        'GUI with JavaFX' => true,
                    ],
                    'unitAllocations' => [],
                    'exclusionRules' => ['lastNPapers' => 0, 'excludeExamYearsBack' => 0],
                ],
                'last_used_at' => null,
            ]
        );
    }
}
