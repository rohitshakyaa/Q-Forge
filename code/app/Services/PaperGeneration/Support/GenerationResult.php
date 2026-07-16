<?php

namespace App\Services\PaperGeneration\Support;

use App\Models\Question;

/**
 * The outcome of a generation run. On success: a fully-filled set of selections
 * plus an all-pass constraint checklist. On failure: a best-effort partial set
 * of selections, the failing constraints, and missing_slots naming the shortfall.
 */
class GenerationResult
{
    /**
     * @param  array<int, Question>  $selections  slotIndex => chosen Question (may be partial)
     * @param  ConstraintResult[]  $constraintResults
     * @param  MissingSlot[]  $missingSlots
     */
    public function __construct(
        public readonly bool $satisfiable,
        public readonly CompiledBlueprint $blueprint,
        public readonly array $selections,
        public readonly array $constraintResults,
        public readonly array $missingSlots,
    ) {
    }

    /**
     * Group the selected questions into the section-oriented shape the frontend
     * Paper interface expects: [{ label, note, questions: [{ no, text, marks, unit, ai }] }].
     */
    public function toSections(): array
    {
        $sections = [];

        foreach ($this->blueprint->slots as $slot) {
            $question = $this->selections[$slot->index] ?? null;
            if ($question === null) {
                continue;
            }

            $label = $slot->sectionLabel;
            if (! isset($sections[$label])) {
                $sections[$label] = [
                    'label' => $label,
                    'note' => "Each question carries {$slot->marks} marks.",
                    'questions' => [],
                ];
            }

            $sections[$label]['questions'][] = [
                'no' => $slot->displayNo,
                'question_id' => $question->id,
                'text' => $question->text,
                'marks' => $slot->marks,
                'unit' => $this->blueprint->unitNames[$question->unit_id] ?? null,
                'ai' => $question->source === 'ai',
            ];
        }

        return array_values($sections);
    }

    public function constraintResultsArray(): array
    {
        return array_map(fn (ConstraintResult $c) => $c->toArray(), $this->constraintResults);
    }

    public function missingSlotsArray(): array
    {
        return array_map(fn (MissingSlot $m) => $m->toArray(), $this->missingSlots);
    }

    /**
     * True when the shortfall cannot be fixed by adding questions to the bank:
     * either the coverage rule demands more units than the paper can ever reach
     * (a question — including an AI-generated one — spans at most
     * MAX_AI_UNITS_PER_QUESTION units), or the per-unit maximums cannot fill
     * every slot. AI bank expansion adds questions, not slots or cap headroom,
     * so it is futile here — the teacher must adjust the blueprint.
     */
    public function coverageStructurallyInfeasible(): bool
    {
        return $this->blueprint->structurallyInfeasible();
    }

    /** Allowed units beyond the paper's coverage capacity, or 0 when it fits. */
    public function coverageSlotDeficit(): int
    {
        return $this->blueprint->coverageCapacityDeficit();
    }

    /**
     * A plain-language explanation for a structurally-infeasible blueprint, or
     * null when the shortfall is an ordinary (expandable) bank deficit.
     */
    public function coverageDeficitMessage(): ?string
    {
        $units = count($this->blueprint->allowedUnitIds);
        $slots = count($this->blueprint->slots);

        if ($this->blueprint->coverageCapacityDeficit() > 0) {
            $capacity = CompiledBlueprint::MAX_AI_UNITS_PER_QUESTION * $slots;

            return "This blueprint requires all {$units} enabled units, but it only has {$slots} question "
                ."slots — even with questions spanning two units, at most {$capacity} units can be covered. "
                .'Add more questions to the blueprint or enable fewer units. AI can\'t fix this: it adds '
                .'questions to the bank (covering at most two units each), not slots to the paper.';
        }

        if ($this->blueprint->capDeficit() > 0) {
            $capSum = array_sum($this->blueprint->unitCaps);

            return "The per-unit maximums allow only {$capSum} questions in total, but the paper has "
                ."{$slots} slots. Raise some unit maximums or remove them. AI can't fix this: every "
                .'generated question would still count against a full unit.';
        }

        return null;
    }
}
