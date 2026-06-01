<?php

namespace App\Services\PaperGeneration\Support;

/**
 * One question slot in a paper: a single position to be filled by exactly one
 * approved question of the given type + marks. Sections are flattened into an
 * ordered list of slots by the BlueprintCompiler.
 */
class Slot
{
    public function __construct(
        public readonly int $index,
        public readonly string $sectionLabel,
        public readonly string $type,
        public readonly int $marks,
        public readonly int $displayNo,
    ) {
    }
}
