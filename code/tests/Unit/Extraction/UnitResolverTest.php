<?php

namespace Tests\Unit\Extraction;

use App\Models\Unit;
use App\Services\Extraction\UnitResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class UnitResolverTest extends TestCase
{
    private function resolver(): UnitResolver
    {
        // Deliberately out of order, and with a gap at position 3, to prove the
        // resolver trusts `position` rather than array order.
        return new UnitResolver(new Collection([
            $this->unit(id: 20, position: 2),
            $this->unit(id: 10, position: 1),
            $this->unit(id: 40, position: 4),
        ]));
    }

    private function unit(int $id, int $position): Unit
    {
        $unit = new Unit(['name' => "Unit {$position}", 'position' => $position]);
        $unit->id = $id;

        return $unit;
    }

    public function test_it_resolves_an_arabic_unit_number_by_position(): void
    {
        $this->assertSame(20, $this->resolver()->resolve('Unit 2')?->id);
    }

    public function test_it_resolves_a_roman_unit_number(): void
    {
        $this->assertSame(20, $this->resolver()->resolve('Unit-II')?->id);
        $this->assertSame(40, $this->resolver()->resolve('unit iv')?->id);
    }

    public function test_position_wins_over_ordering(): void
    {
        // Position 4 exists even though it is only the third unit in the list.
        $this->assertSame(40, $this->resolver()->resolve('Unit 4')?->id);
    }

    public function test_it_falls_back_to_the_nth_unit_when_no_position_matches(): void
    {
        // No unit has position 3, so "Unit 3" means the third one by order.
        $this->assertSame(40, $this->resolver()->resolve('Unit 3')?->id);
    }

    public function test_a_number_beyond_the_last_unit_resolves_to_nothing(): void
    {
        $this->assertNull($this->resolver()->resolve('Unit 9'));
    }

    public function test_group_and_section_headings_are_never_guessed_at(): void
    {
        // These divide the answer sheet, not the syllabus.
        $this->assertNull($this->resolver()->resolve('Group B'));
        $this->assertNull($this->resolver()->resolve('Section A'));
    }

    public function test_an_absent_hint_resolves_to_nothing(): void
    {
        $this->assertNull($this->resolver()->resolve(null));
        $this->assertNull($this->resolver()->resolve('   '));
    }
}
