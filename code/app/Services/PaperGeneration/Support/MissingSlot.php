<?php

namespace App\Services\PaperGeneration\Support;

/**
 * Describes exactly what the bank lacks for an infeasible blueprint, e.g.
 * "2× 10-mark, Unit 3". Feeds the frontend shortfall panel and, in M5, tells
 * the AI bank-expansion job precisely which slots to target.
 */
class MissingSlot
{
    public function __construct(
        public readonly string $sectionLabel,
        public readonly string $type,
        public readonly int $marks,
        public readonly ?string $unit,
        public readonly int $need,
        // Server-set unit id the AI top-up (M5) should target, or null when the slot
        // has no unit (unrestricted blueprint). Carried here because the generator
        // already knows it — the job must not reverse-parse the `unit` name string.
        public readonly ?int $unitId = null,
    ) {
    }

    /** Human-readable shortfall, e.g. "2× 10-mark, Trees". */
    public function describe(): string
    {
        $base = "{$this->need}× {$this->marks}-mark {$this->type}";

        return $this->unit ? "{$base}, {$this->unit}" : $base;
    }

    public function toArray(): array
    {
        return [
            'section_label' => $this->sectionLabel,
            'type' => $this->type,
            'marks' => $this->marks,
            'unit' => $this->unit,
            'unit_id' => $this->unitId,
            'need' => $this->need,
            'description' => $this->describe(),
        ];
    }
}
