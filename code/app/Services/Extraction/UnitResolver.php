<?php

namespace App\Services\Extraction;

use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Maps a printed unit heading ("Unit 3", "Unit-III") onto one of a subject's units.
 *
 * Only `Unit` headings are resolved. "Group B" and "Section A" divide a paper into
 * answer sections, not syllabus units — guessing that Group B means the second unit
 * would silently mis-tag the bank, so those stay unresolved for a human to assign.
 */
class UnitResolver
{
    private const ROMAN = [
        'i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4,
        'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8,
        'ix' => 9, 'x' => 10,
    ];

    /** @var Collection<int, Unit> ordered by position */
    private Collection $units;

    /**
     * @param  Collection<int, Unit>  $units
     */
    public function __construct(Collection $units)
    {
        $this->units = $units->sortBy('position')->values();
    }

    public function resolve(?string $hint): ?Unit
    {
        if ($hint === null || trim($hint) === '') {
            return null;
        }

        $ordinal = $this->ordinalFrom($hint);
        if ($ordinal === null) {
            return null;
        }

        // A unit whose `position` says so wins over mere ordering, because
        // positions are what the subject's author actually numbered.
        return $this->units->firstWhere('position', $ordinal)
            ?? $this->units->get($ordinal - 1);
    }

    /**
     * Pull the unit number out of a heading, or null if it isn't a unit heading.
     */
    private function ordinalFrom(string $hint): ?int
    {
        if (! preg_match('/^\s*unit\s*[-–—:]?\s*(\w+)\s*$/i', $hint, $matches)) {
            return null;
        }

        $token = strtolower($matches[1]);

        if (ctype_digit($token)) {
            $ordinal = (int) $token;

            return $ordinal > 0 ? $ordinal : null;
        }

        return self::ROMAN[$token] ?? null;
    }
}
