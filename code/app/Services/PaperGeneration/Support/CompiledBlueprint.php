<?php

namespace App\Services\PaperGeneration\Support;

/**
 * A blueprint deserialized into the inputs the generation engine needs:
 * ordered slots (authoritative for the paper structure), the set of allowed
 * units (hard filter + coverage rule), per-unit maximums (hard caps compiled
 * from unitAllocations counts; the marks column stays display-only), and the
 * cross-paper exclusion window (wired in M3).
 */
class CompiledBlueprint
{
    /**
     * The AI top-up generates questions spanning at most this many units, which
     * bounds the unit coverage a paper of S slots can ever reach (S × this).
     */
    public const MAX_AI_UNITS_PER_QUESTION = 2;

    /**
     * @param  Slot[]  $slots
     * @param  int[]  $allowedUnitIds  empty = no unit restriction / no coverage rule
     * @param  array<int, string>  $unitNames  unitId => name, for reporting
     * @param  array<int, int>  $unitCaps  unitId => max questions; only capped allowed units present
     */
    public function __construct(
        public readonly int $subjectId,
        public readonly int $totalMarks,
        public readonly array $slots,
        public readonly array $allowedUnitIds,
        public readonly array $unitNames,
        public readonly array $unitCaps,
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

    /** True when every allowed unit carries a cap (so caps bound the whole paper). */
    public function capsAreExhaustive(): bool
    {
        return $this->allowedUnitIds !== []
            && count($this->unitCaps) === count($this->allowedUnitIds);
    }

    /**
     * Slots the caps cannot account for. Every selected question overlaps at
     * least one allowed unit (CandidateFilter guarantees it), so each slot
     * consumes at least one cap unit; when every allowed unit is capped and the
     * caps sum below the slot count, no assignment can exist.
     */
    public function capDeficit(): int
    {
        if (! $this->capsAreExhaustive()) {
            return 0;
        }

        return max(0, count($this->slots) - array_sum($this->unitCaps));
    }

    /**
     * Allowed units beyond what the paper can ever cover, even with every slot
     * holding a question that spans MAX_AI_UNITS_PER_QUESTION units.
     */
    public function coverageCapacityDeficit(): int
    {
        return max(0, count($this->allowedUnitIds) - self::MAX_AI_UNITS_PER_QUESTION * count($this->slots));
    }

    /**
     * True when no bank content — existing or AI-generated — can satisfy this
     * blueprint: the coverage rule outstrips the paper's unit capacity, or the
     * per-unit maximums cannot fill every slot.
     */
    public function structurallyInfeasible(): bool
    {
        return $this->coverageCapacityDeficit() > 0 || $this->capDeficit() > 0;
    }
}
