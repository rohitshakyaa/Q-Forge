<?php

namespace App\Services\PaperGeneration\Support;

use App\Models\Paper;

/**
 * The single shared view-model for a *persisted* paper. Built from `papers` +
 * `paper_questions` (not from a generation run), it is the one source of paper
 * shape consumed by all three render targets:
 *   - the JSON resource (GET /papers/{id})
 *   - the Blade template (dompdf -> PDF)
 *   - the PhpWord builder (-> DOCX)
 *
 * Header metadata (institute, instructions) comes from config/qforge.php so PDF
 * and DOCX share one letterhead. The section shape mirrors
 * GenerationResult::toSections so the frontend `Paper` interface is unchanged.
 */
class PaperViewModel
{
    public function __construct(private readonly Paper $paper)
    {
        $this->paper->loadMissing([
            'subject',
            'paperQuestions.question',
            'paperQuestions.unit',
        ]);
    }

    public static function for(Paper $paper): self
    {
        return new self($paper);
    }

    /**
     * Letterhead + exam metadata.
     *
     * @return array{institute:string, title:string, subject:?string, date:?string, duration:int, marks:int, instructions:string}
     */
    public function header(): array
    {
        return [
            'institute' => config('qforge.institute'),
            'title' => $this->paper->name,
            'subject' => $this->paper->subject?->code,
            'date' => $this->paper->generated_at?->toFormattedDateString(),
            'duration' => (int) $this->paper->duration,
            'marks' => (int) $this->paper->total_marks,
            'instructions' => config('qforge.paper.instructions'),
        ];
    }

    /**
     * Questions grouped into ordered sections by section_label, in display_no order.
     *
     * @return array<int, array{label:string, note:string, questions:array<int, array{no:int, question_id:int, text:string, marks:int, unit:?string, ai:bool}>}>
     */
    public function sections(): array
    {
        $sections = [];

        foreach ($this->paper->paperQuestions as $pq) {
            $label = $pq->section_label;

            if (! isset($sections[$label])) {
                $sections[$label] = [
                    'label' => $label,
                    'note' => "Each question carries {$pq->marks} marks.",
                    'questions' => [],
                ];
            }

            $sections[$label]['questions'][] = [
                'no' => (int) $pq->display_no,
                'question_id' => (int) $pq->question_id,
                'text' => $pq->question?->text ?? '',
                'marks' => (int) $pq->marks,
                'unit' => $pq->unit?->name,
                'ai' => (bool) $pq->is_ai,
            ];
        }

        return array_values($sections);
    }

    /** Full payload for the JSON resource consumed by the frontend papers store. */
    public function toArray(): array
    {
        $header = $this->header();

        return [
            'id' => $this->paper->id,
            'name' => $this->paper->name,
            'subject' => $header['subject'],
            'marks' => $header['marks'],
            'duration' => $header['duration'],
            'status' => $this->paper->status,
            'export_count' => (int) $this->paper->export_count,
            'questions' => $this->paper->paperQuestions->count(),
            'generated_at' => $this->paper->generated_at?->toIso8601String(),
            'sections' => $this->sections(),
        ];
    }

    /** A filesystem-safe base filename, e.g. "StandardMidterm". */
    public function filename(): string
    {
        $base = preg_replace('/[^A-Za-z0-9]+/', '', $this->paper->name) ?: 'Paper';

        return $base;
    }
}
