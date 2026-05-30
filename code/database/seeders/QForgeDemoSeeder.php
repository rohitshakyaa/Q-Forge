<?php

namespace Database\Seeders;

use App\Models\Blueprint;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class QForgeDemoSeeder extends Seeder
{
    /**
     * Seed a usable demo dataset: CS301 + CS303 with units and a pool of approved
     * questions across types/marks/units (enough to exercise generation in M2),
     * plus one teacher-owned blueprint. CS302 is intentionally left unseeded for
     * the manual acceptance walkthrough.
     */
    public function run(): void
    {
        $cs301 = $this->seedSubject('CS301', 'Data Structures', [
            'Arrays & Linked Lists' => [
                ['short', 4, 'easy', 'What is the time complexity of inserting at the head of a linked list?'],
                ['short', 4, 'easy', 'Differentiate between a stack and a queue.'],
                ['long', 10, 'medium', 'Compare arrays and linked lists in terms of memory layout and access time.'],
                ['mcq', 2, 'easy', 'Which structure uses LIFO ordering? (a) Queue (b) Stack (c) Tree (d) Graph'],
            ],
            'Trees' => [
                ['short', 4, 'easy', 'Define a binary search tree.'],
                ['long', 10, 'medium', 'Explain AVL tree rotations with examples.'],
                ['long', 5, 'medium', 'Describe how a min-heap supports a priority queue.'],
                ['mcq', 2, 'medium', 'A balanced BST with n nodes has height of order? (a) n (b) log n (c) n^2 (d) 1'],
            ],
            'Graphs' => [
                ['short', 5, 'medium', 'State the difference between BFS and DFS.'],
                ['long', 10, 'hard', "Explain Dijkstra's shortest path algorithm with a worked example."],
                ['short', 4, 'easy', 'How is a graph represented using an adjacency list?'],
            ],
            'Hashing' => [
                ['short', 5, 'easy', 'Describe open addressing for collision resolution.'],
                ['mcq', 2, 'easy', 'A good hash function should minimise? (a) memory (b) collisions (c) keys (d) buckets'],
                ['long', 10, 'medium', 'Discuss separate chaining versus open addressing.'],
            ],
            'Advanced Structures' => [
                ['short', 4, 'medium', 'What is a trie used for?'],
                ['long', 10, 'hard', 'Explain the union-find data structure with path compression.'],
            ],
        ]);

        $this->seedSubject('CS303', 'Database Management', [
            'Introduction' => [
                ['short', 5, 'easy', 'What is a DBMS? List its advantages over file systems.'],
                ['mcq', 2, 'easy', 'Which is NOT a DBMS? (a) MySQL (b) PostgreSQL (c) Excel (d) Oracle'],
            ],
            'Relational Model & SQL' => [
                ['short', 6, 'medium', 'Write SQL to find the second-highest salary from an Employee table.'],
                ['long', 10, 'medium', 'Explain ER diagrams with a university example.'],
                ['short', 4, 'easy', 'Define primary key and foreign key.'],
                ['mcq', 2, 'medium', 'Which SQL clause filters grouped rows? (a) WHERE (b) HAVING (c) ORDER BY (d) LIMIT'],
            ],
            'Normalization' => [
                ['long', 8, 'medium', 'Define BCNF and explain it with an example.'],
                ['short', 5, 'medium', 'What is a functional dependency?'],
            ],
            'Transactions' => [
                ['short', 5, 'easy', 'List the ACID properties.'],
                ['long', 10, 'hard', 'Explain two-phase locking and how it prevents anomalies.'],
            ],
            'Indexing' => [
                ['short', 4, 'medium', 'Why are B+ trees preferred for database indexes?'],
                ['mcq', 2, 'easy', 'An index primarily improves? (a) inserts (b) reads (c) storage (d) backups'],
            ],
        ]);

        $this->seedBlueprint($cs301);
    }

    /**
     * @param  array<string, array<int, array{0:string,1:int,2:string,3:string}>>  $units
     */
    private function seedSubject(string $code, string $name, array $units): Subject
    {
        $subject = Subject::updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'description' => "$name — core syllabus for question generation.",
                'syllabus' => "## $code – $name\n\n".collect(array_keys($units))
                    ->map(fn ($u, $i) => '### Unit '.($i + 1).": $u")
                    ->implode("\n"),
            ]
        );

        // Idempotent reseed: clear existing units/questions for this subject.
        $subject->questions()->delete();
        $subject->units()->delete();

        $position = 1;
        foreach ($units as $unitName => $questions) {
            $unit = $subject->units()->create([
                'name' => $unitName,
                'position' => $position++,
            ]);

            foreach ($questions as [$type, $marks, $difficulty, $text]) {
                Question::create([
                    'subject_id' => $subject->id,
                    'unit_id' => $unit->id,
                    'type' => $type,
                    'marks' => $marks,
                    'difficulty' => $difficulty,
                    'text' => $text,
                    'source' => 'manual',
                    'status' => 'approved',
                    'used_count' => 0,
                ]);
            }
        }

        return $subject->fresh();
    }

    private function seedBlueprint(Subject $subject): void
    {
        $teacher = User::where('email', 'teacher@qforge.com')->first();
        if (! $teacher) {
            return;
        }

        $unitNames = $subject->units()->orderBy('position')->pluck('name')->take(3)->all();
        $unitRules = collect($unitNames)->mapWithKeys(fn ($n) => [$n => true])->all();
        $unitAllocations = collect($unitNames)->mapWithKeys(fn ($n) => [
            $n => [['marks' => 4, 'count' => 1], ['marks' => 10, 'count' => 1]],
        ])->all();

        Blueprint::updateOrCreate(
            ['owner_id' => $teacher->id, 'name' => 'Standard Midterm'],
            [
                'subject_id' => $subject->id,
                'total_marks' => 50,
                'duration' => 90,
                'ai_assist' => false,
                'definition' => [
                    'sections' => [
                        ['id' => 1, 'name' => 'Section A — Short Answer', 'type' => 'Short Answer', 'count' => 5, 'marksEach' => 4, 'mandatory' => true],
                        ['id' => 2, 'name' => 'Section B — Long Answer', 'type' => 'Long Answer', 'count' => 3, 'marksEach' => 10, 'mandatory' => false],
                    ],
                    'unitRules' => $unitRules,
                    'unitAllocations' => $unitAllocations,
                    'exclusionRules' => ['lastNPapers' => 2, 'reuseThreshold' => 3],
                ],
                'last_used_at' => null,
            ]
        );
    }
}
