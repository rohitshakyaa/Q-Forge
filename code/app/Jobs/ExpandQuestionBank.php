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
 * this job validates it, stamps the slot's own type + marks + units (never trusting
 * the model), and stores survivors as ordinary `questions` rows with
 * `source=ai, status=approved` — immediately usable by the generator so a
 * previously infeasible blueprint can become satisfiable on re-generate. A slot
 * may target TWO units (coverage rule beyond the slot count): the question is
 * asked to span both, the first id becomes its primary and both are tagged.
 */
class ExpandQuestionBank implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /** A single bad item shouldn't re-trigger infeasibility, so over-ask by this much. */
    private const BUFFER = 2;

    /**
     * A small local model routinely returns fewer questions than requested (asked for
     * 12, hands back 6), so one call rarely closes a deficit. Re-ask, requesting only
     * the shortfall each round, until the slot is filled — capped so a model that keeps
     * under-delivering (or repeating itself) can't loop forever.
     */
    private const MAX_ATTEMPTS_PER_SLOT = 3;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  array<int, array{section_label:string, type:string, marks:int, unit_ids:int[], need:int}>  $slots
     */
    public function __construct(
        public readonly int $blueprintId,
        public readonly array $slots,
    ) {}

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
            $totalStored += $this->fillSlot($python, $grounding, $blueprint, $slot);
        }

        Log::info('ExpandQuestionBank: complete', [
            'blueprint' => $blueprint->id, 'slots' => count($this->slots), 'stored' => $totalStored,
        ]);
    }

    /**
     * Top up a single missing slot, re-asking the model until the shortfall is filled,
     * an attempt round adds nothing new (the model has run out of distinct questions),
     * or the attempt cap is hit. Each round requests only the *remaining* need so the
     * bank isn't over-inflated once the deficit is closed. Returns the count stored.
     *
     * @param  array{section_label:string, type:string, marks:int, unit_ids:int[], need:int}  $slot
     */
    private function fillSlot(PythonService $python, GroundingBuilder $grounding, Blueprint $blueprint, array $slot): int
    {
        $subject = $blueprint->subject;
        $type = $slot['type'];
        $marks = (int) $slot['marks'];
        $unitIds = array_values(array_map('intval', $slot['unit_ids'] ?? []));
        $need = max(1, (int) $slot['need']);

        // Load the target units preserving the slot's order — index 0 is the
        // primary the generator chose (scarcest unit first).
        $unitsById = Unit::query()->whereIn('id', $unitIds)->get()->keyBy('id');
        $units = array_values(array_filter(array_map(
            fn (int $id) => $unitsById->get($id),
            $unitIds,
        )));
        $unitIds = array_map(fn (Unit $u) => (int) $u->id, $units);

        // Grounding is deterministic for a (subject, units, type, marks) tuple, so build
        // it once and reuse it across retry rounds.
        $block = $grounding->for($subject, $units, $type, $marks);
        foreach ($block['notes'] as $note) {
            Log::info("ExpandQuestionBank: grounding note — {$note}", [
                'blueprint' => $blueprint->id, 'unit_ids' => $unitIds, 'type' => $type,
            ]);
        }

        // The grounding block Laravel assembled and handed to Python. The exact
        // wrapped prompt + raw model output live in the Python logs (LLM_DEBUG=1).
        Log::debug('ExpandQuestionBank: grounding block sent to Python', [
            'blueprint' => $blueprint->id, 'type' => $type, 'marks' => $marks,
            'unit_ids' => $unitIds, 'grounding' => $block['text'],
        ]);

        $unitNames = array_map(fn (Unit $u) => (string) $u->name, $units);

        $stored = 0;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS_PER_SLOT && $stored < $need; $attempt++) {
            $requested = ($need - $stored) + self::BUFFER;

            try {
                $result = $python->generateQuestions($block['text'], $type, $marks, $requested, $unitNames);
            } catch (\RuntimeException $e) {
                Log::error('ExpandQuestionBank: generation failed for slot', [
                    'blueprint' => $blueprint->id, 'type' => $type, 'marks' => $marks,
                    'unit_ids' => $unitIds, 'attempt' => $attempt, 'error' => $e->getMessage(),
                ]);

                break; // Give up on this slot; other slots may still succeed.
            }

            foreach ($result['errors'] as $error) {
                Log::info("ExpandQuestionBank: python rejected an item — {$error}");
            }

            Log::debug('ExpandQuestionBank: questions returned from Python', [
                'blueprint' => $blueprint->id, 'type' => $type, 'unit_ids' => $unitIds,
                'attempt' => $attempt, 'data' => $result['data'], 'errors' => $result['errors'],
            ]);

            $added = $this->storeSurvivors($subject->id, $unitIds, $type, $marks, $result['data']);
            $stored += $added;

            Log::info('ExpandQuestionBank: slot attempt', [
                'blueprint' => $blueprint->id, 'type' => $type, 'marks' => $marks,
                'unit_ids' => $unitIds, 'attempt' => $attempt, 'need' => $need,
                'requested' => $requested, 'added' => $added, 'stored_so_far' => $stored,
                'rejected' => count($result['errors']),
            ]);

            // Nothing new landed — the model is repeating itself or can't produce more;
            // further identical requests won't help, so stop wasting LLM calls.
            if ($added === 0) {
                break;
            }
        }

        Log::info('ExpandQuestionBank: slot topped up', [
            'blueprint' => $blueprint->id, 'type' => $type, 'marks' => $marks,
            'unit_ids' => $unitIds, 'need' => $need, 'stored' => $stored, 'filled' => $stored >= $need,
        ]);

        return $stored;
    }

    /**
     * Persist the valid candidates as approved AI questions. Laravel is authoritative
     * for type + marks + units: the model's echoed values are only a sanity signal
     * (logged on mismatch), and the slot's own type/marks/units are what get stored —
     * so an AI question always matches the slot CandidateFilter will query. With two
     * target units the first becomes the primary and both are tagged on the pivot.
     *
     * @param  int[]  $unitIds
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function storeSurvivors(int $subjectId, array $unitIds, string $type, int $marks, array $candidates): int
    {
        // Text-based dedup across retry rounds: a small local model tends to repeat
        // itself, and near-identical rows would satisfy the count while producing a
        // paper with duplicated wording (the "no repeated questions" rule is by id, so
        // it can't catch them). Seed the seen-set from the existing bank — which already
        // includes rows earlier rounds of this job inserted — then grow it as we store.
        $seen = Question::where('subject_id', $subjectId)
            ->pluck('text')
            ->mapWithKeys(fn ($t) => [$this->normalize((string) $t) => true])
            ->all();

        $stored = 0;

        foreach ($candidates as $candidate) {
            $text = trim((string) ($candidate['text'] ?? ''));
            if ($text === '') {
                continue; // Defensive: Python already filters these.
            }

            $key = $this->normalize($text);
            if (isset($seen[$key])) {
                continue; // Duplicate of an existing or just-stored question.
            }
            $seen[$key] = true;

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

            $question = Question::create([
                'subject_id' => $subjectId,
                'unit_id' => $unitIds[0] ?? null,  // primary = scarcest target unit
                'type' => $type,      // stamped from the slot, not the model
                'marks' => $marks,    // stamped from the slot, not the model
                'text' => $text,
                'source' => 'ai',
                'status' => 'approved',
                'attributes' => $attributes ?: null,
                'used_count' => 0,
            ]);
            // Tag every target unit (never sync([]) — that would detach the primary).
            $question->syncUnitLinks($unitIds !== [] ? $unitIds : null);

            $stored++;
        }

        return $stored;
    }

    /** Fold a question's text to a canonical form for duplicate detection. */
    private function normalize(string $text): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($text)));
    }
}
