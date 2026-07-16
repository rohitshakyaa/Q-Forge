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
                ['short', 4, 'What is the time complexity of inserting at the head of a linked list?'],
                ['short', 4, 'Differentiate between a stack and a queue.'],
                ['short', 4, 'What is a circular linked list and where is it used?'],
                ['short', 4, 'Explain how a doubly linked list differs from a singly linked list.'],
                ['long', 10, 'Compare arrays and linked lists in terms of memory layout and access time.'],
                ['long', 10, 'Explain how a dynamic array grows and analyse its amortised insertion cost.'],
                ['long', 10, 'Describe how to detect and remove a cycle in a singly linked list.'],
                ['mcq', 2, 'Which structure uses LIFO ordering? (a) Queue (b) Stack (c) Tree (d) Graph'],
            ],
            'Trees' => [
                ['short', 4, 'Define a binary search tree.'],
                ['short', 4, 'What is the difference between a complete and a full binary tree?'],
                ['short', 4, 'What is the height of a binary tree with a single node?'],
                ['short', 4, 'Define an in-order traversal and give its output for a small BST.'],
                ['long', 10, 'Explain AVL tree rotations with examples.'],
                ['long', 10, 'Describe red-black tree insertion and the re-colouring cases.'],
                ['long', 10, 'Explain B-tree insertion and how node splitting maintains balance.'],
                ['long', 5, 'Describe how a min-heap supports a priority queue.'],
                ['mcq', 2, 'A balanced BST with n nodes has height of order? (a) n (b) log n (c) n^2 (d) 1'],
            ],
            'Graphs' => [
                ['short', 5, 'State the difference between BFS and DFS.'],
                ['short', 4, 'How is a graph represented using an adjacency list?'],
                ['short', 4, 'How does an adjacency matrix represent a graph?'],
                ['short', 4, 'What is a strongly connected component in a directed graph?'],
                ['short', 4, 'Define a minimum spanning tree and give one application.'],
                ['long', 10, "Explain Dijkstra's shortest path algorithm with a worked example."],
                ['long', 10, 'Explain topological sorting with a worked example on a DAG.'],
                ['long', 10, "Explain Kruskal's algorithm for a minimum spanning tree with an example."],
            ],
            'Hashing' => [
                ['short', 5, 'Describe open addressing for collision resolution.'],
                ['mcq', 2, 'A good hash function should minimise? (a) memory (b) collisions (c) keys (d) buckets'],
                ['long', 10, 'Discuss separate chaining versus open addressing.'],
            ],
            'Advanced Structures' => [
                ['short', 4, 'What is a trie used for?'],
                ['long', 10, 'Explain the union-find data structure with path compression.'],
            ],
        ]);

        $cs303 = $this->seedSubject('CS303', 'Database Management', [
            'Introduction' => [
                ['short', 5, 'What is a DBMS? List its advantages over file systems.'],
                ['mcq', 2, 'Which is NOT a DBMS? (a) MySQL (b) PostgreSQL (c) Excel (d) Oracle'],
            ],
            'Relational Model & SQL' => [
                ['short', 6, 'Write SQL to find the second-highest salary from an Employee table.'],
                ['long', 10, 'Explain ER diagrams with a university example.'],
                ['short', 4, 'Define primary key and foreign key.'],
                ['mcq', 2, 'Which SQL clause filters grouped rows? (a) WHERE (b) HAVING (c) ORDER BY (d) LIMIT'],
            ],
            'Normalization' => [
                ['long', 8, 'Define BCNF and explain it with an example.'],
                ['short', 5, 'What is a functional dependency?'],
            ],
            'Transactions' => [
                ['short', 5, 'List the ACID properties.'],
                ['long', 10, 'Explain two-phase locking and how it prevents anomalies.'],
            ],
            'Indexing' => [
                ['short', 4, 'Why are B+ trees preferred for database indexes?'],
                ['mcq', 2, 'An index primarily improves? (a) inserts (b) reads (c) storage (d) backups'],
            ],
        ]);

        $this->seedBlueprint($cs301);
        $this->seedInfeasibleBlueprint($cs303);
    }

    /**
     * @param  array<string, array<int, array{0:string,1:int,2:string}>>  $units
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

            foreach ($questions as [$type, $marks, $text]) {
                Question::create([
                    'subject_id' => $subject->id,
                    'unit_id' => $unit->id,
                    'type' => $type,
                    'marks' => $marks,
                    'text' => $text,
                    'source' => 'manual',
                    'status' => 'approved',
                    'used_count' => 0,
                ])->syncUnitLinks();
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

    /**
     * A deliberately unsatisfiable blueprint for the "cannot satisfy" demo:
     * it demands 20-mark long-answer questions, a marks value the bank never
     * holds (questions top out at 10), so generation always returns a precise
     * shortfall instead of a paper — regardless of how deep the bank is seeded.
     */
    private function seedInfeasibleBlueprint(Subject $subject): void
    {
        $teacher = User::where('email', 'teacher@qforge.com')->first();
        if (! $teacher) {
            return;
        }

        $unitNames = $subject->units()->orderBy('position')->pluck('name')->all();
        $unitRules = collect($unitNames)->mapWithKeys(fn ($n) => [$n => true])->all();

        Blueprint::updateOrCreate(
            ['owner_id' => $teacher->id, 'name' => 'Comprehensive Final (needs a bigger bank)'],
            [
                'subject_id' => $subject->id,
                'total_marks' => 60,
                'duration' => 120,
                'ai_assist' => false,
                'definition' => [
                    'sections' => [
                        ['id' => 1, 'name' => 'Section A — Essay', 'type' => 'Long Answer', 'count' => 3, 'marksEach' => 20, 'mandatory' => true],
                    ],
                    'unitRules' => $unitRules,
                    'unitAllocations' => [],
                    'exclusionRules' => ['lastNPapers' => 2, 'reuseThreshold' => 3],
                ],
                'last_used_at' => null,
            ]
        );
    }
}
