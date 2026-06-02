<?php

namespace Tests\Unit;

use App\Models\Paper;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Services\ImportedPaperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ImportedPaperServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_an_imported_paper_from_existing_and_new_questions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        $existing = Question::factory()->count(2)->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'marks' => 4, 'status' => 'approved', 'used_count' => 0,
        ]);

        $examDate = Carbon::parse('2024-12-15');

        $paper = (new ImportedPaperService)->record(
            $subject,
            'CSC409 Final 2024',
            $examDate,
            $existing->pluck('id')->all(),
            [['text' => 'New long question', 'type' => 'long', 'marks' => 10, 'unit_id' => $unit->id]],
            $admin,
        );

        // Paper shape.
        $this->assertSame('imported', $paper->origin);
        $this->assertNull($paper->blueprint_id);
        $this->assertSame($admin->id, $paper->owner_id);
        $this->assertSame('saved', $paper->status);
        $this->assertSame(0, $paper->duration);
        $this->assertSame(4 + 4 + 10, $paper->total_marks);
        $this->assertTrue($examDate->equalTo($paper->generated_at));

        // Links: 2 existing + 1 new, sequential display_no, "Imported" section.
        $links = $paper->paperQuestions()->get();
        $this->assertCount(3, $links);
        $this->assertSame([1, 2, 3], $links->pluck('display_no')->all());
        $this->assertSame(['Imported'], $links->pluck('section_label')->unique()->values()->all());
        $this->assertFalse((bool) $links->pluck('is_ai')->contains(true));

        // The inline question was persisted as extracted+approved.
        $created = Question::where('text', 'New long question')->first();
        $this->assertNotNull($created);
        $this->assertSame('extracted', $created->source);
        $this->assertSame('approved', $created->status);
        $this->assertSame($subject->id, $created->subject_id);

        // used_count bumped once per distinct linked question.
        foreach ($existing as $q) {
            $this->assertSame(1, $q->fresh()->used_count);
        }
        $this->assertSame(1, $created->fresh()->used_count);
    }

    public function test_deduplicates_repeated_existing_ids(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $q = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'marks' => 5, 'status' => 'approved', 'used_count' => 0,
        ]);

        $paper = (new ImportedPaperService)->record(
            $subject, 'Dup', Carbon::parse('2024-01-01'), [$q->id, $q->id], [], $admin,
        );

        $this->assertSame(1, $paper->paperQuestions()->count());
        $this->assertSame(5, $paper->total_marks);
        $this->assertSame(1, $q->fresh()->used_count);
    }
}
