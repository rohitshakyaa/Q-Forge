<?php

namespace App\Jobs;

use App\Models\Blueprint;
use App\Models\Question;
use App\Models\Unit;
use App\Services\AiExpansion\GroundingBuilder;
use App\Services\PythonService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tops up the question bank with AI-authored questions for a blueprint's named
 * missing slots (M5). AI is supportive, not authoritative: Python writes the text,
 * this job validates it, stamps the slot's own type + marks (never trusting the
 * model), resolves the target unit, and stores survivors as ordinary `questions`
 * rows with `source=ai, status=approved` — immediately usable by the generator so
 * a previously infeasible blueprint can become satisfiable on re-generate.
 */
class ExpandQuestionBank implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /** A single bad item shouldn't re-trigger infeasibility, so over-ask by this much. */
    private const BUFFER = 2;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  array<int, array{section_label:string, type:string, marks:int, unit_id:?int, need:int}>  $slots
     */
    public function __construct(
        public readonly int $blueprintId,
        public readonly array $slots,
    ) {
    }

    public function handle(PythonService $python, GroundingBuilder $grounding): void
    {
        $blueprint = Blueprint::with('subject')->find($this->blueprintId);

        if ($blueprint === null || $blueprint->subject === null) {
            Log::warning('ExpandQuestionBank: blueprint or subject missing', ['blueprint' => $this->blueprintId]);

            return;
        }

        $subject = $blueprint->subject;
        $totalStored = 0;

        foreach ($this->slots as $slot) {
            $type = $slot['type'];
            $marks = (int) $slot['marks'];
            $unitId = $slot['unit_id'] ?? null;
            $need = max(1, (int) $slot['need']);

            $unit = $unitId !== null ? Unit::find($unitId) : null;

            $block = $grounding->for($subject, $unit, $type, $marks);
            foreach ($block['notes'] as $note) {
                Log::info("ExpandQuestionBank: grounding note — {$note}", [
                    'blueprint' => $blueprint->id, 'unit_id' => $unitId, 'type' => $type,
                ]);
            }

            // The grounding block Laravel assembled and handed to Python. The exact
            // wrapped prompt + raw model output live in the Python logs (LLM_DEBUG=1).
            Log::debug('ExpandQuestionBank: grounding block sent to Python', [
                'blueprint' => $blueprint->id, 'type' => $type, 'marks' => $marks,
                'unit_id' => $unitId, 'requested' => $need + self::BUFFER,
                'grounding' => $block['text'],
            ]);

            try {
                $result = $python->generateQuestions($block['text'], $type, $marks, $need + self::BUFFER);
            } catch (\RuntimeException $e) {
                Log::error('ExpandQuestionBank: generation failed for slot', [
                    'blueprint' => $blueprint->id, 'type' => $type, 'marks' => $marks,
                    'unit_id' => $unitId, 'error' => $e->getMessage(),
                ]);

                continue; // Other slots may still succeed.
            }

            foreach ($result['errors'] as $error) {
                Log::info("ExpandQuestionBank: python rejected an item — {$error}");
            }

            Log::debug('ExpandQuestionBank: questions returned from Python', [
                'blueprint' => $blueprint->id, 'type' => $type, 'unit_id' => $unitId,
                'data' => $result['data'], 'errors' => $result['errors'],
            ]);

            $stored = $this->storeSurvivors($subject->id, $unitId, $type, $marks, $result['data']);
            $totalStored += $stored;

            Log::info('ExpandQuestionBank: slot topped up', [
                'blueprint' => $blueprint->id, 'type' => $type, 'marks' => $marks,
                'unit_id' => $unitId, 'need' => $need, 'requested' => $need + self::BUFFER,
                'stored' => $stored, 'rejected' => count($result['errors']),
            ]);
        }

        Log::info('ExpandQuestionBank: complete', [
            'blueprint' => $blueprint->id, 'slots' => count($this->slots), 'stored' => $totalStored,
        ]);
    }

    /**
     * Persist the valid candidates as approved AI questions. Laravel is authoritative
     * for type + marks: the model's echoed values are only a sanity signal (logged on
     * mismatch), and the slot's own type/marks are what get stored — so an AI question
     * always matches the slot CandidateFilter will query.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function storeSurvivors(int $subjectId, ?int $unitId, string $type, int $marks, array $candidates): int
    {
        $stored = 0;

        foreach ($candidates as $candidate) {
            $text = trim((string) ($candidate['text'] ?? ''));
            if ($text === '') {
                continue; // Defensive: Python already filters these.
            }

            if (($candidate['type'] ?? $type) !== $type || (int) ($candidate['marks'] ?? $marks) !== $marks) {
                Log::warning('ExpandQuestionBank: model echoed a mismatched type/marks; stamping the slot values', [
                    'slot_type' => $type, 'slot_marks' => $marks,
                    'echoed_type' => $candidate['type'] ?? null, 'echoed_marks' => $candidate['marks'] ?? null,
                ]);
            }

            $attributes = null;
            if ($type === 'mcq') {
                $attributes = array_filter([
                    'options' => $candidate['options'] ?? null,
                    'answer' => $candidate['answer'] ?? null,
                ], fn ($v) => $v !== null);
            }

            Question::create([
                'subject_id' => $subjectId,
                'unit_id' => $unitId,
                'type' => $type,      // stamped from the slot, not the model
                'marks' => $marks,    // stamped from the slot, not the model
                'difficulty' => 'medium',
                'text' => $text,
                'source' => 'ai',
                'status' => 'approved',
                'attributes' => $attributes ?: null,
                'used_count' => 0,
            ]);

            $stored++;
        }

        return $stored;
    }
}
