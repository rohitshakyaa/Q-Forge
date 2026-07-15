<?php

namespace App\Services\AiExpansion;

use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;

/**
 * Assembles the per-slot grounding block that Laravel hands to Python
 * `/generate-questions`. This is the "RAG" of M5: deterministic SQL retrieval by
 * primary key — no embeddings, no vector store, no fine-tuning.
 *
 * Grounding is mandatory: an ungrounded model invents plausible-but-off-syllabus
 * questions. The block is `subjects.syllabus` (course overview) + the slot unit's
 * `units.content` (unit body) + up to three approved exemplars of the same
 * subject/unit/type, for style and difficulty. It degrades gracefully — a unit
 * predating the M4.1 content backfill falls back to the syllabus alone — and
 * records what it had to drop in `notes` so the job can log it.
 */
class GroundingBuilder
{
    private const MAX_EXEMPLARS = 3;

    /**
     * @return array{text: string, notes: string[]}
     */
    public function for(Subject $subject, ?Unit $unit, string $type, int $marks): array
    {
        $notes = [];
        $parts = [];

        $syllabus = trim((string) $subject->syllabus);
        if ($syllabus !== '') {
            $parts[] = "# Course syllabus\n{$syllabus}";
        } else {
            $notes[] = 'subject syllabus is empty';
        }

        if ($unit !== null) {
            $content = trim((string) $unit->content);
            if ($content !== '') {
                $parts[] = "# Unit: {$unit->name}\n{$content}";
            } else {
                // A unit predating the M4.1 content backfill: keep the name as a hint
                // and lean on the syllabus rather than sending a bare slot spec.
                $parts[] = "# Unit: {$unit->name}\n(No detailed unit content on file — use the course syllabus above.)";
                $notes[] = "unit '{$unit->name}' has no content; grounded on syllabus only";
            }
        }

        $exemplars = $this->exemplars($subject, $unit, $type);
        if ($exemplars->isNotEmpty()) {
            $lines = $exemplars->values()->map(
                fn (Question $q, int $i) => ($i + 1).'. '.trim($q->text)
            )->implode("\n");
            $parts[] = "# Example {$type} questions (match this style and difficulty)\n{$lines}";
        } else {
            $notes[] = 'no exemplars available (thin unit)';
        }

        if (empty($parts)) {
            // Last resort: never send nothing. Give the model the bare target.
            $unitLabel = $unit?->name ?? 'the course';
            $parts[] = "Write {$marks}-mark {$type} questions for {$unitLabel} in {$subject->name}.";
            $notes[] = 'no syllabus, unit content, or exemplars — sent bare slot spec';
        }

        return ['text' => implode("\n\n", $parts), 'notes' => $notes];
    }

    /**
     * Up to three approved questions matching the slot's subject (+ unit when the
     * slot names one) and type. Scarcity is expected — the unit is thin, which is
     * why we are expanding — so 0–3 is normal; we never fabricate to pad.
     *
     * @return \Illuminate\Support\Collection<int, Question>
     */
    private function exemplars(Subject $subject, ?Unit $unit, string $type)
    {
        return Question::query()
            ->where('subject_id', $subject->id)
            ->where('status', 'approved')
            ->where('type', $type)
            ->when($unit !== null, fn ($q) => $q->where('unit_id', $unit->id))
            ->orderBy('id')
            ->limit(self::MAX_EXEMPLARS)
            ->get();
    }
}
