<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use App\Services\PaperGeneration\Contracts\SimilarityGuard;
use Illuminate\Support\Collection;

/**
 * The "no semantic dedup" guard: every candidate is fresh. This is the engine's
 * default, so the generator stays a pure, deterministic function of the bank
 * when no guard is supplied (unit tests, the bank-expansion feasibility probe).
 */
final class NullSimilarityGuard implements SimilarityGuard
{
    public function prime(Collection $questions): void
    {
        // Nothing to prepare.
    }

    public function tooSimilar(Question $candidate, array $chosen): bool
    {
        return false;
    }
}
