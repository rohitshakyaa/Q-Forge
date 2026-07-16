<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Qdrant's REST API (M6 — RAG, docs/RAG-GUIDE.md).
 *
 * Qdrant is an INDEX, not a database: MySQL stays the source of truth, every
 * vector here is derived (text → embedding), and `qforge:rag:reindex` can
 * rebuild everything from scratch. Only Laravel talks to Qdrant — Python does
 * no retrieval (its own contract, see python-service .../llm/base.py) and the
 * frontend never sees it.
 */
class QdrantClient
{
    /** Approved bank questions — one point per question (Phase 1: dedup). */
    public const COLLECTION_QUESTIONS = 'questions';

    /** Chunked unit/syllabus content (Phase 2: grounding retrieval). */
    public const COLLECTION_CHUNKS = 'chunks';

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.qdrant.base_url'))
            ->timeout((int) config('services.qdrant.timeout'))
            ->acceptJson();
    }

    public function healthy(): bool
    {
        try {
            return $this->client()->get('/readyz')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create the collection if it does not exist yet. Idempotent — safe to call
     * on every boot path. Cosine distance, because that is the similarity the
     * whole design reasons in (RAG-GUIDE §3).
     */
    public function ensureCollection(string $name): void
    {
        if ($this->client()->get("/collections/{$name}/exists")->json('result.exists') === true) {
            return;
        }

        $this->createCollection($name);
    }

    /**
     * Drop and recreate a collection — the first step of a re-index, and the
     * escape hatch after an embedding-model swap (old vectors are incomparable).
     */
    public function recreateCollection(string $name): void
    {
        $this->client()->delete("/collections/{$name}");
        $this->createCollection($name);
    }

    /**
     * Insert-or-replace points. Each point: `id` (the MySQL row id — the link
     * back to the source of truth), `vector`, and a `payload` used for filtered
     * search (subject_id, status, ...). `wait=true` so a search issued right
     * after (dedup does exactly this) sees the new point.
     *
     * @param  array<int, array{id: int|string, vector: array<int, float>, payload?: array<string, mixed>}>  $points
     */
    public function upsert(string $collection, array $points): void
    {
        $response = $this->client()->put(
            "/collections/{$collection}/points?wait=true",
            ['points' => array_values($points)],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "qdrant upsert into '{$collection}' returned HTTP {$response->status()}"
            );
        }
    }

    /**
     * Top-`$limit` nearest neighbours of `$vector`, optionally restricted by a
     * Qdrant filter (e.g. `['must' => [['key' => 'subject_id', 'match' => ['value' => 3]]]]`
     * — "among this subject's questions only").
     *
     * @param  array<int, float>  $vector
     * @param  array<string, mixed>|null  $filter
     * @return array<int, array{id: int|string, score: float, payload: array<string, mixed>}>
     *         sorted best-first; `score` is cosine similarity in [-1, 1]
     */
    public function search(string $collection, array $vector, int $limit = 5, ?array $filter = null): array
    {
        $body = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
        ];

        if ($filter !== null) {
            $body['filter'] = $filter;
        }

        $response = $this->client()->post("/collections/{$collection}/points/search", $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "qdrant search in '{$collection}' returned HTTP {$response->status()}"
            );
        }

        return array_map(fn (array $hit) => [
            'id' => $hit['id'],
            'score' => (float) $hit['score'],
            'payload' => $hit['payload'] ?? [],
        ], $response->json('result') ?? []);
    }

    /**
     * Remove points by id — e.g. when a question is deleted or rejected, its
     * vector must not keep matching searches.
     *
     * @param  array<int, int|string>  $ids
     */
    public function deletePoints(string $collection, array $ids): void
    {
        $response = $this->client()->post(
            "/collections/{$collection}/points/delete?wait=true",
            ['points' => array_values($ids)],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "qdrant delete from '{$collection}' returned HTTP {$response->status()}"
            );
        }
    }

    /**
     * Remove every point matching a payload filter — e.g. all of one subject's
     * chunk vectors before a re-chunk replaces them (row ids don't survive a
     * re-chunk, so deletion must go by payload, not id).
     *
     * @param  array<string, mixed>  $filter
     */
    public function deleteByFilter(string $collection, array $filter): void
    {
        $response = $this->client()->post(
            "/collections/{$collection}/points/delete?wait=true",
            ['filter' => $filter],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "qdrant filtered delete from '{$collection}' returned HTTP {$response->status()}"
            );
        }
    }

    private function createCollection(string $name): void
    {
        $response = $this->client()->put("/collections/{$name}", [
            'vectors' => [
                'size' => (int) config('services.qdrant.vector_size'),
                'distance' => 'Cosine',
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "qdrant create collection '{$name}' returned HTTP {$response->status()}"
            );
        }
    }
}
