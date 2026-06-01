<?php

namespace App\Services\PaperGeneration\Support;

/**
 * A single pass/fail line in the constraint checklist. Shape mirrors the
 * frontend `ConstraintResult` interface exactly: { label, expected, got, pass }.
 * `pass` is true | false | null (null = informational, e.g. AI-assisted in M5).
 */
class ConstraintResult
{
    public function __construct(
        public readonly string $label,
        public readonly string $expected,
        public readonly string $got,
        public readonly ?bool $pass,
    ) {
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'expected' => $this->expected,
            'got' => $this->got,
            'pass' => $this->pass,
        ];
    }
}
