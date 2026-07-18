<?php

namespace Tests\Unit\PaperGeneration;

use App\Services\PaperGeneration\Support\TieBreaker;
use PHPUnit\Framework\TestCase;

class TieBreakerTest extends TestCase
{
    public function test_a_null_seed_reproduces_the_raw_id_order(): void
    {
        // No seed = legacy behaviour: the key IS the id, so ordering is unchanged.
        $this->assertSame(7, TieBreaker::key(null, 7));
        $this->assertSame(42, TieBreaker::key(null, 42));
    }

    public function test_a_given_seed_is_stable_across_calls(): void
    {
        $this->assertSame(
            TieBreaker::key(12345, 7),
            TieBreaker::key(12345, 7),
        );
    }

    public function test_different_seeds_reorder_the_same_ids(): void
    {
        $ids = range(1, 30);

        $orderFor = function (?int $seed) use ($ids): array {
            $keyed = $ids;
            usort($keyed, fn ($a, $b) => TieBreaker::key($seed, $a) <=> TieBreaker::key($seed, $b));

            return $keyed;
        };

        $legacy = $orderFor(null);
        $seedA = $orderFor(111);
        $seedB = $orderFor(999);

        // Legacy order is ascending id.
        $this->assertSame($ids, $legacy);
        // A seed shuffles that order, and two seeds disagree with each other.
        $this->assertNotSame($legacy, $seedA);
        $this->assertNotSame($seedA, $seedB);
        // No id is lost or duplicated by the reshuffle.
        $this->assertEqualsCanonicalizing($ids, $seedA);
    }
}
