<?php

namespace App\Services\Rag;

use InvalidArgumentException;

/**
 * The one piece of geometry RAG needs (docs/RAG-GUIDE.md §3).
 *
 * Qdrant does this at scale over the whole index; this PHP twin exists for the
 * handful of comparisons that never reach Qdrant — e.g. an expansion batch
 * checking new candidates against each other before anything is stored.
 */
final class VectorMath
{
    /**
     * Cosine similarity: the angle between two vectors, length ignored.
     * ≈1 same meaning, ≈0 unrelated (for text embeddings; see the guide).
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            throw new InvalidArgumentException('cosine() needs two equal-length, non-empty vectors');
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0; // A zero vector has no direction to compare.
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
