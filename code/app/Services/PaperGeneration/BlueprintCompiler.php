<?php

namespace App\Services\PaperGeneration;

use App\Models\Blueprint;
use App\Models\Unit;
use App\Services\PaperGeneration\Support\CompiledBlueprint;
use App\Services\PaperGeneration\Support\Slot;

/**
 * Turns a stored blueprint's `definition` JSON into the engine's inputs.
 *
 * Sections are authoritative for the paper structure (ordered slots). unitRules
 * is both the hard candidate filter and the coverage rule. unitAllocations
 * counts compile into per-unit MAX caps (a unit without rows is uncapped); the
 * marks column in those rows stays display-only and is never enforced.
 */
class BlueprintCompiler
{
    public function compile(Blueprint $blueprint): CompiledBlueprint
    {
        $definition = $blueprint->definition ?? [];
        $sections = $definition['sections'] ?? [];

        // unitId => name for every unit in the subject (used for filtering + reporting).
        $unitsByName = Unit::query()
            ->where('subject_id', $blueprint->subject_id)
            ->pluck('id', 'name');
        $unitNames = $unitsByName->flip()->all();

        $slots = $this->buildSlots($sections);
        $allowedUnitIds = $this->resolveAllowedUnits($definition['unitRules'] ?? [], $unitsByName->all());
        $unitCaps = $this->resolveCaps($definition['unitAllocations'] ?? [], $unitsByName->all(), $allowedUnitIds);
        $lastNPapers = (int) ($definition['exclusionRules']['lastNPapers'] ?? 0);

        return new CompiledBlueprint(
            subjectId: (int) $blueprint->subject_id,
            totalMarks: (int) $blueprint->total_marks,
            slots: $slots,
            allowedUnitIds: $allowedUnitIds,
            unitNames: $unitNames,
            unitCaps: $unitCaps,
            lastNPapers: $lastNPapers,
        );
    }

    /**
     * Flatten sections into an ordered list of single-question slots.
     *
     * @return Slot[]
     */
    private function buildSlots(array $sections): array
    {
        $slots = [];
        $index = 0;
        $displayNo = 1;

        foreach ($sections as $section) {
            $label = $section['name'] ?? 'Section';
            $type = $this->normalizeType($section['type'] ?? '');
            $marks = (int) ($section['marksEach'] ?? 0);
            $count = (int) ($section['count'] ?? 0);

            for ($i = 0; $i < $count; $i++) {
                $slots[] = new Slot(
                    index: $index++,
                    sectionLabel: $label,
                    type: $type,
                    marks: $marks,
                    displayNo: $displayNo++,
                );
            }
        }

        return $slots;
    }

    /**
     * Map the truthy unitRules names to unit ids within the subject.
     * Empty rules => no restriction (and no coverage requirement).
     *
     * @param  array<string, int>  $unitsByName
     * @return int[]
     */
    private function resolveAllowedUnits(array $unitRules, array $unitsByName): array
    {
        $allowed = [];
        foreach ($unitRules as $name => $enabled) {
            if ($enabled && isset($unitsByName[$name])) {
                $allowed[] = (int) $unitsByName[$name];
            }
        }

        return $allowed;
    }

    /**
     * Compile unitAllocations counts into per-unit MAX caps: a unit's cap is
     * the sum of its rows' counts. Only allowed units can carry a cap (rows for
     * a disabled unit are dropped); a unit with no rows, or rows summing to <= 0,
     * is uncapped. The marks column is display-only and ignored here.
     *
     * @param  array<string, int>  $unitsByName
     * @param  int[]  $allowedUnitIds
     * @return array<int, int>  unitId => max questions
     */
    private function resolveCaps(array $allocations, array $unitsByName, array $allowedUnitIds): array
    {
        $caps = [];
        foreach ($allocations as $name => $rows) {
            if (! isset($unitsByName[$name])) {
                continue;
            }

            $unitId = (int) $unitsByName[$name];
            if (! in_array($unitId, $allowedUnitIds, true)) {
                continue;
            }

            $cap = 0;
            foreach ((array) $rows as $row) {
                $cap += (int) ($row['count'] ?? 0);
            }

            if ($cap > 0) {
                $caps[$unitId] = $cap;
            }
        }

        return $caps;
    }

    /** Normalize a section's display type to a question `type` enum value. */
    private function normalizeType(string $raw): string
    {
        $type = strtolower(trim($raw));

        return match ($type) {
            'short answer', 'short' => 'short',
            'long answer', 'long' => 'long',
            'mcq', 'multiple choice', 'multiple-choice' => 'mcq',
            default => str_replace(' ', '-', $type),
        };
    }
}
