<?php

namespace App\Services\PaperGeneration\Support;

/**
 * A blueprint deserialized into the inputs the generation engine needs:
 * ordered slots (authoritative for the paper structure), the set of allowed
 * units (hard filter + coverage rule), per-unit allocations (soft balancing
 * hint only in M2), and the cross-paper exclusion window (wired in M3).
 */
class CompiledBlueprint
{
    /**
     * @param  Slot[]  $slots
     * @param  int[]  $allowedUnitIds  empty = no unit restriction / no coverage rule
     * @param  array<int, string>  $unitNames  unitId => name, for reporting
     * @param  array<int, array<int, array{marks:int, count:int}>>  $unitAllocations  soft, keyed by unitId
     */
    public function __construct(
        public readonly int $subjectId,
        public readonly int $totalMarks,
        public readonly array $slots,
        public readonly array $allowedUnitIds,
        public readonly array $unitNames,
        public readonly array $unitAllocations,
        public readonly int $lastNPapers,
    ) {
    }

    /** Ordered, de-duplicated section labels in slot order. */
    public function sectionLabels(): array
    {
        $labels = [];
        foreach ($this->slots as $slot) {
            if (! in_array($slot->sectionLabel, $labels, true)) {
                $labels[] = $slot->sectionLabel;
            }
        }

        return $labels;
    }
}
