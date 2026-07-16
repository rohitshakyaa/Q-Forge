<?php

namespace Tests\Feature;

use App\Services\PythonService;
use App\Services\Rag\QdrantClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * M6 Phase 0 — the embedding pipeline plumbing (docs/RAG-GUIDE.md).
 *
 * Everything HTTP is faked: pytest covers the real /embed endpoint, and Qdrant
 * is exercised for request/response shape only — these tests pin Laravel's side
 * of both contracts.
 */
class RagInfrastructureTest extends TestCase
{
    // Phase 1: the reindex command reads the questions table, so migrate.
    use RefreshDatabase;

    // ---- PythonService::embed ------------------------------------------------

    public function test_embed_returns_model_dimensions_and_vectors(): void
    {
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => [
                    'model' => 'nomic-embed-text',
                    'dimensions' => 768,
                    'embeddings' => [[0.1, 0.2], [0.3, 0.4]],
                ],
                'errors' => [],
            ]),
        ]);

        $data = app(PythonService::class)->embed(['What is TCP?', 'Define a B-tree.']);

        $this->assertSame('nomic-embed-text', $data['model']);
        $this->assertSame(768, $data['dimensions']);
        $this->assertCount(2, $data['embeddings']);

        Http::assertSent(fn ($request) => $request->url() === 'http://qforge_python:8000/embed'
            && $request['texts'] === ['What is TCP?', 'Define a B-tree.']);
    }

    public function test_embed_surfaces_python_reported_errors(): void
    {
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'error', 'data' => null, 'errors' => ['embedding failed: model missing'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('model missing');

        app(PythonService::class)->embed(['anything']);
    }

    // ---- QdrantClient ---------------------------------------------------------

    public function test_ensure_collection_creates_only_when_missing(): void
    {
        Http::fake([
            'qforge_qdrant:6333/collections/questions/exists' => Http::response([
                'result' => ['exists' => false],
            ]),
            'qforge_qdrant:6333/collections/questions' => Http::response(['result' => true]),
        ]);

        app(QdrantClient::class)->ensureCollection('questions');

        // The create call carries the configured geometry: 768 dims, cosine.
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/collections/questions')
            && $request['vectors'] === ['size' => 768, 'distance' => 'Cosine']);
    }

    public function test_ensure_collection_skips_create_when_present(): void
    {
        Http::fake([
            'qforge_qdrant:6333/collections/questions/exists' => Http::response([
                'result' => ['exists' => true],
            ]),
        ]);

        app(QdrantClient::class)->ensureCollection('questions');

        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_search_returns_scored_hits_best_first(): void
    {
        Http::fake([
            'qforge_qdrant:6333/collections/questions/points/search' => Http::response([
                'result' => [
                    ['id' => 42, 'score' => 0.97, 'payload' => ['subject_id' => 3]],
                    ['id' => 7, 'score' => 0.61, 'payload' => ['subject_id' => 3]],
                ],
            ]),
        ]);

        $hits = app(QdrantClient::class)->search(
            'questions',
            [0.1, 0.2],
            limit: 2,
            filter: ['must' => [['key' => 'subject_id', 'match' => ['value' => 3]]]],
        );

        $this->assertSame([42, 7], array_column($hits, 'id'));
        $this->assertSame(0.97, $hits[0]['score']);
        $this->assertSame(['subject_id' => 3], $hits[0]['payload']);

        Http::assertSent(fn ($request) => $request['limit'] === 2
            && $request['with_payload'] === true
            && $request['filter']['must'][0]['key'] === 'subject_id');
    }

    public function test_upsert_failure_raises(): void
    {
        Http::fake(['qforge_qdrant:6333/*' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);

        app(QdrantClient::class)->upsert('questions', [
            ['id' => 1, 'vector' => [0.1], 'payload' => []],
        ]);
    }

    // ---- qforge:rag:reindex ----------------------------------------------------

    public function test_reindex_ensures_both_collections(): void
    {
        Http::fake([
            'qforge_qdrant:6333/readyz' => Http::response('ok'),
            'qforge_qdrant:6333/collections/*/exists' => Http::response(['result' => ['exists' => false]]),
            'qforge_qdrant:6333/collections/*' => Http::response(['result' => true]),
        ]);

        $this->artisan('qforge:rag:reindex')
            ->expectsOutputToContain("Ensured collection 'questions'")
            ->expectsOutputToContain("Ensured collection 'chunks'")
            ->assertSuccessful();
    }

    public function test_reindex_fresh_recreates_collections(): void
    {
        Http::fake([
            'qforge_qdrant:6333/readyz' => Http::response('ok'),
            'qforge_qdrant:6333/collections/*' => Http::response(['result' => true]),
        ]);

        $this->artisan('qforge:rag:reindex --fresh')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/collections/questions'));
    }

    public function test_reindex_fails_cleanly_when_qdrant_is_down(): void
    {
        Http::fake(['qforge_qdrant:6333/*' => Http::response('', 503)]);

        $this->artisan('qforge:rag:reindex')->assertFailed();
    }
}
