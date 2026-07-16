<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use App\Services\PaperGeneration\Support\CompiledBlueprint;
use App\Services\PaperGeneration\Support\ConstraintResult;

/**
 * Scores a set of selections against the blueprint and returns a structured
 * pass/fail line per constraint (feeds the frontend ConstraintResult[]):
 * per-section counts, total marks, unit coverage, and no in-paper repetition.
 */
class ConstraintValidator
{
    /**
     * @param  array<int, Question>  $selections  slotIndex => Question (may be partial)
     * @return ConstraintResult[]
     */
    public function validate(CompiledBlueprint $blueprint, array $selections): array
    {
        $results = [];

        // Per-section count.
        foreach ($blueprint->sectionLabels() as $label) {
            $expected = 0;
            $got = 0;
            foreach ($blueprint->slots as $slot) {
                if ($slot->sectionLabel !== $label) {
                    continue;
                }
                $expected++;
                if (isset($selections[$slot->index])) {
                    $got++;
                }
            }

            $results[] = new ConstraintResult(
                label: $label,
                expected: "{$expected} questions",
                got: "{$got} questions",
                pass: $got === $expected,
            );
        }

        // Total marks.
        $gotMarks = 0;
        foreach ($blueprint->slots as $slot) {
            if (isset($selections[$slot->index])) {
                $gotMarks += $slot->marks;
            }
        }
        $results[] = new ConstraintResult(
            label: 'Total marks',
            expected: (string) $blueprint->totalMarks,
            got: (string) $gotMarks,
            pass: $gotMarks === $blueprint->totalMarks,
        );

        // Unit coverage (only when the blueprint restricts units). A multi-unit
        // question covers every allowed unit it is tagged with.
        if (! empty($blueprint->allowedUnitIds)) {
            $coveredIds = collect($selections)
                ->flatMap(fn (Question $q) => $q->taggedUnitIds())
                ->unique()
                ->intersect($blueprint->allowedUnitIds);
            $expectedCount = count($blueprint->allowedUnitIds);

            $results[] = new ConstraintResult(
                label: 'Unit coverage',
                expected: "{$expectedCount} units",
                got: $coveredIds->count().' units',
                pass: $coveredIds->count() === $expectedCount,
            );
        }

        // No repeated questions within the paper.
        $ids = collect($selections)->map(fn (Question $q) => $q->id);
        $repeats = $ids->count() - $ids->unique()->count();
        $results[] = new ConstraintResult(
            label: 'No repeated questions',
            expected: '0 repeats',
            got: "{$repeats} repeats",
            pass: $repeats === 0,
        );

        return $results;
    }

    /** True only when every constraint passes (null is treated as non-failing). */
    public function allPass(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->pass === false) {
                return false;
            }
        }

        return true;
    }
}
