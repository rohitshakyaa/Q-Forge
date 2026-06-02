<?php

namespace App\Services;

use App\Models\Paper;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records a real, historical exam as a first-class `papers` row (origin=imported)
 * with its paper_questions, so the M3 repetition window can cover it subject-wide
 * (see ADR 0001 / M3.1). Pure data entry — no generation algorithm is involved.
 *
 * The same entry point serves both the manual admin endpoint (M3.1) and, later,
 * the M4 past-paper upload job which will call record() with extracted questions.
 */
class ImportedPaperService
{
    /**
     * Persist an imported exam in one transaction: create any inline questions,
     * link the full (deduped) question set into a new imported paper, and bump
     * used_count once per distinct question.
     *
     * @param  int[]  $existingQuestionIds  ids of questions already in the bank
     * @param  array<int, array{text:string, type:string, marks:int, unit_id:int}>  $newQuestions  inline questions to create
     */
    public function record(
        Subject $subject,
        string $name,
        CarbonInterface $examDate,
        array $existingQuestionIds,
        array $newQuestions,
        User $uploader,
    ): Paper {
        return DB::transaction(function () use ($subject, $name, $examDate, $existingQuestionIds, $newQuestions, $uploader) {
            // Inline questions are an admin asserting a real exam's contents, so
            // they land approved and extracted (bypassing the M4 review queue).
            $createdIds = [];
            foreach ($newQuestions as $payload) {
                $question = Question::create([
                    'subject_id' => $subject->id,
                    'unit_id' => $payload['unit_id'],
                    'type' => $payload['type'],
                    'marks' => $payload['marks'],
                    'text' => $payload['text'],
                    'source' => 'extracted',
                    'status' => 'approved',
                ]);
                $createdIds[] = $question->id;
            }

            // Existing ids first (input order, deduped), then the freshly created
            // ones — this is also the display order on the recorded paper.
            $linkedIds = collect($existingQuestionIds)
                ->unique()
                ->values()
                ->merge($createdIds)
                ->all();

            // Snapshot marks/unit from the question rows (keyed for ordered lookup).
            $questions = Question::whereIn('id', $linkedIds)->get()->keyBy('id');

            $totalMarks = $questions->sum('marks');

            $paper = Paper::create([
                'owner_id' => $uploader->id,
                'blueprint_id' => null,
                'origin' => 'imported',
                'subject_id' => $subject->id,
                'name' => $name,
                'total_marks' => $totalMarks,
                'duration' => 0,
                'status' => 'saved',
                'export_count' => 0,
                'generated_at' => $examDate,
            ]);

            $displayNo = 1;
            foreach ($linkedIds as $id) {
                $question = $questions[$id];

                $paper->paperQuestions()->create([
                    'question_id' => $question->id,
                    'unit_id' => $question->unit_id,
                    'section_label' => 'Imported',
                    'display_no' => $displayNo++,
                    'marks' => $question->marks,
                    'is_ai' => false,
                ]);
            }

            // A real past exam is real usage — consistent with M3's generate-persist.
            if (! empty($linkedIds)) {
                Question::whereIn('id', $linkedIds)->increment('used_count');
            }

            return $paper;
        });
    }
}
