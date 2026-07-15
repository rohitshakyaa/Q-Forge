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
     * True when the shortfall cannot be fixed by adding questions to the bank,
     * because the unit-coverage rule demands more distinct units than the paper has
     * slots (each slot holds one question, so at most one unit apiece). AI bank
     * expansion adds questions, not slots, so it is futile here — the teacher must
     * add questions to the blueprint or enable fewer units.
     */
    public function coverageStructurallyInfeasible(): bool
    {
        return count($this->blueprint->allowedUnitIds) > count($this->blueprint->slots);
    }

    /** How many more question slots the coverage rule needs, or 0 when it fits. */
    public function coverageSlotDeficit(): int
    {
        return max(0, count($this->blueprint->allowedUnitIds) - count($this->blueprint->slots));
    }

    /**
     * A plain-language explanation for a structurally-infeasible coverage rule, or
     * null when the shortfall is an ordinary (expandable) bank deficit.
     */
    public function coverageDeficitMessage(): ?string
    {
        if (! $this->coverageStructurallyInfeasible()) {
            return null;
        }

        $units = count($this->blueprint->allowedUnitIds);
        $slots = count($this->blueprint->slots);
        $deficit = $this->coverageSlotDeficit();
        $questions = $deficit === 1 ? 'question' : 'questions';
        $unitWord = $deficit === 1 ? 'unit' : 'units';

        return "This blueprint requires all {$units} enabled units, but it only has {$slots} questions — "
            ."a question can cover just one unit. Add at least {$deficit} more {$questions}, or enable "
            ."{$deficit} fewer {$unitWord}. AI can't fix this: it adds questions to the bank, not slots to the paper.";
    }
}
