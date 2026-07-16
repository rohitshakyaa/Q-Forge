<?php

namespace App\Services\PaperGeneration\Support;

/**
 * Describes exactly what the bank lacks for an infeasible blueprint, e.g.
 * "2× 10-mark, Unit 3" or "1× 5-mark, Trees + Graphs". Feeds the frontend
 * shortfall panel and tells the AI bank-expansion job precisely which slots
 * to target.
 */
class MissingSlot
{
    /**
     * @param  int[]  $unitIds  Server-set unit ids the AI top-up should target
     *   (ordered least-populated first — index 0 becomes the AI question's
     *   primary unit). Empty when the slot has no unit (unrestricted blueprint).
     *   Two ids ask for a question spanning BOTH units. Carried here because
     *   the generator already knows them — the job must not reverse-parse the
     *   `unit` name string.
     */
    public function __construct(
        public readonly string $sectionLabel,
        public readonly string $type,
        public readonly int $marks,
        public readonly ?string $unit,
        public readonly int $need,
        public readonly array $unitIds = [],
    ) {
    }

    /** Human-readable shortfall, e.g. "2× 10-mark, Trees + Graphs". */
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
            // unit_id kept for back-compat with consumers of the old shape.
            'unit_id' => $this->unitIds[0] ?? null,
            'unit_ids' => $this->unitIds,
            'need' => $this->need,
            'description' => $this->describe(),
        ];
    }
}
