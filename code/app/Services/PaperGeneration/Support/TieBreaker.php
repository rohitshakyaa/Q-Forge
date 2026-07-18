<?php

namespace App\Services\PaperGeneration\Support;

/**
 * Final, deterministic tie-break key for candidate ordering.
 *
 * The selectors rank by coverage, then least-recently-used (`used_count`), and
 * historically broke any remaining ties by ascending `id` — which made a
 * blueprint + bank produce one fixed paper, identical for every teacher and on
 * every "regenerate". A per-run seed replaces that last `id` tie-break with a
 * stable pseudo-random key: same seed → same paper (reproducible, test-safe),
 * different seed → a different valid paper.
 *
 * The seed only reorders questions the ranking already considers equal, so the
 * coverage-first + LRU rotation is fully preserved. A null seed reproduces the
 * old pure-`id` ordering exactly, so the deterministic engine tests are green
 * without a seed.
 */
final class TieBreaker
{
    /**
     * @param  int|null  $seed  null = legacy id order; any int = seeded shuffle
     * @param  int  $id  the question's stable row id
     */
    public static function key(?int $seed, int $id): int
    {
        if ($seed === null) {
            return $id;
        }

        // crc32 over "seed:id" gives a stable, well-spread ordering per seed.
        // Deterministic for a given (seed, id) pair, so a run is reproducible.
        return crc32($seed.':'.$id);
    }
}
