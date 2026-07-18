<?php

namespace Tests\Feature;

use App\Jobs\ExpandQuestionBank;
use App\Jobs\SyncQuestionEmbedding;
use App\Models\DocumentUpload;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Services\Extraction\CandidateImporter;
use App\Services\Rag\VectorMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * M6 Phase 1 — embedding-based dedup (docs/RAG-GUIDE.md).
 *
 * RAG is off for the wider suite (phpunit.xml); these tests enable it and fake
 * every HTTP edge, controlling the vectors directly so similarity outcomes are
 * exact. pytest covers real embedding; RagInfrastructureTest covers the client
 * contracts; this file covers the *decisions* built on them.
 */
class RagDedupTest extends TestCase
{
    use RefreshDatabase;

    private function enableRag(): void
    {
        config(['services.qdrant.enabled' => true]);
    }

    /** @param array<int, array<int, float>> $vectors */
    private function fakeEmbed(array $vectors): void
    {
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text/prefixed', 'dimensions' => count($vectors[0] ?? [0]), 'embeddings' => $vectors],
                'errors' => [],
            ]),
        ]);
    }

    private function bank(): array
    {
        $subject = Subject::factory()->create(['syllabus' => '# CS\nNetworks.']);
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1, 'content' => 'TCP/IP.']);

        return [$subject, $unit];
    }

    // ---- the observer → job pipeline -------------------------------------------

    public function test_approved_question_writes_queue_a_vector_sync(): void
    {
        $this->enableRag();
        Queue::fake();
        [$subject, $unit] = $this->bank();

        $question = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'status' => 'approved',
        ]);

        Queue::assertPushed(SyncQuestionEmbedding::class, fn ($job) => $job->questionId === $question->id);
    }

    public function test_pending_question_writes_do_not_queue_a_sync(): void
    {
        $this->enableRag();
        Queue::fake();
        [$subject, $unit] = $this->bank();

        Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'status' => 'pending',
        ]);

        // Not assertNothingPushed: the fixtures legitimately queue a Phase 2
        // chunk sync (subject/unit content) — pending *questions* must not sync.
        Queue::assertNotPushed(SyncQuestionEmbedding::class);
    }

    public function test_leaving_the_approved_pool_queues_a_sync_so_the_vector_is_removed(): void
    {
        [$subject, $unit] = $this->bank(); // RAG still off: setup writes stay silent.
        $question = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'status' => 'approved',
        ]);

        $this->enableRag();
        Queue::fake();

        $question->update(['status' => 'rejected']);

        Queue::assertPushed(SyncQuestionEmbedding::class, fn ($job) => $job->questionId === $question->id);
    }

    public function test_rag_disabled_never_queues(): void
    {
        Queue::fake();
        [$subject, $unit] = $this->bank();

        Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'status' => 'approved',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_sync_job_upserts_an_approved_question_with_payload(): void
    {
        [$subject, $unit] = $this->bank();
        $question = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'status' => 'approved', 'type' => 'short', 'marks' => 5,
        ]);
        $question->syncUnitLinks();

        $this->enableRag();
        $this->fakeEmbed([[0.1, 0.2]]);
        Http::fake(['qforge_qdrant:6333/*' => Http::response(['result' => true])]);

        app()->call([new SyncQuestionEmbedding($question->id), 'handle']);

        Http::assertSent(function ($request) use ($question, $unit) {
            if (! str_contains($request->url(), '/collections/questions/points')) {
                return false;
            }
            $point = $request['points'][0];

            return $point['id'] === $question->id
                && $point['vector'] === [0.1, 0.2]
                && $point['payload']['subject_id'] === $question->subject_id
                && $point['payload']['unit_ids'] === [$unit->id]
                && $point['payload']['embedding_model'] === 'nomic-embed-text/prefixed';
        });
    }

    public function test_sync_job_deletes_the_vector_of_a_non_approved_question(): void
    {
        [$subject, $unit] = $this->bank();
        $question = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'status' => 'rejected',
        ]);

        $this->enableRag();
        Http::fake(['qforge_qdrant:6333/*' => Http::response(['result' => true])]);

        app()->call([new SyncQuestionEmbedding($question->id), 'handle']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/collections/questions/points/delete')
            && $request['points'] === [$question->id]);
    }

    // ---- AI expansion dedup -----------------------------------------------------

    /**
     * Run an expansion whose generator returns two candidates; the fake /embed
     * hands back the given vectors and Qdrant scores the *first* search hit as
     * given (the second search finds nothing).
     */
    private function expandWithVectors(array $vectors, float $bankScore): Subject
    {
        [$subject, $unit] = $this->bank();
        $blueprint = \App\Models\Blueprint::factory()->for($subject)->create();
        $dedupCalls = 0;

        Http::fake([
            'qforge_python:8000/generate-questions' => Http::response([
                'status' => 'success',
                'data' => [
                    ['text' => 'Explain TCP congestion control.', 'type' => 'short', 'marks' => 5],
                    ['text' => 'Describe how TCP avoids congestion.', 'type' => 'short', 'marks' => 5],
                ],
                'errors' => [],
            ]),
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text/prefixed', 'dimensions' => 3, 'embeddings' => $vectors],
                'errors' => [],
            ]),
            // Phase 2: GroundingBuilder also searches this collection (semantic
            // exemplars — its filter carries a `type` condition, dedup's doesn't).
            // Exemplar searches find nothing; the FIRST dedup search scores
            // $bankScore; later dedup searches find nothing (so only in-memory
            // dedup can catch them).
            'qforge_qdrant:6333/collections/questions/points/search' => function ($request) use ($bankScore, &$dedupCalls) {
                $isExemplar = collect($request['filter']['must'] ?? [])
                    ->contains(fn ($cond) => ($cond['key'] ?? null) === 'type');

                if ($isExemplar) {
                    return Http::response(['result' => []]);
                }

                return Http::response(['result' => ++$dedupCalls === 1 && $bankScore > 0
                    ? [['id' => 999, 'score' => $bankScore, 'payload' => []]]
                    : [],
                ]);
            },
            'qforge_qdrant:6333/*' => Http::response(['result' => true]),
        ]);

        $this->enableRag();

        // need=1 keeps the job to a single round: every candidate still passes
        // through dedup (storeSurvivors processes the whole batch regardless).
        (new ExpandQuestionBank($blueprint->id, [[
            'section_label' => 'A', 'type' => 'short', 'marks' => 5,
            'unit_ids' => [$unit->id], 'need' => 1,
        ]]))->handle(
            app(\App\Services\PythonService::class),
            app(\App\Services\AiExpansion\GroundingBuilder::class),
            app(\App\Services\Rag\DuplicateDetector::class),
        );

        return $subject;
    }

    public function test_expansion_drops_a_candidate_that_paraphrases_the_bank(): void
    {
        // Orthogonal vectors: the two candidates are NOT lookalikes of each other,
        // but the bank scores 0.95 against the first — only the second survives.
        $subject = $this->expandWithVectors([[1, 0, 0], [0, 1, 0]], bankScore: 0.95);

        $stored = Question::where('subject_id', $subject->id)->where('source', 'ai')->get();
        $this->assertCount(1, $stored);
        $this->assertSame('Describe how TCP avoids congestion.', $stored[0]->text);
    }

    public function test_expansion_drops_a_candidate_that_repeats_an_earlier_one_in_the_same_batch(): void
    {
        // Identical vectors (cosine 1.0), no bank hit: the first is stored, the
        // second is caught by the in-memory pool — Qdrant never saw either.
        $subject = $this->expandWithVectors([[1, 0, 0], [1, 0, 0]], bankScore: 0.0);

        $this->assertSame(1, Question::where('subject_id', $subject->id)->where('source', 'ai')->count());
    }

    public function test_expansion_stores_both_when_nothing_is_similar(): void
    {
        $subject = $this->expandWithVectors([[1, 0, 0], [0, 1, 0]], bankScore: 0.4);

        $this->assertSame(2, Question::where('subject_id', $subject->id)->where('source', 'ai')->count());
    }

    public function test_expansion_survives_rag_being_down(): void
    {
        [$subject, $unit] = $this->bank();
        $blueprint = \App\Models\Blueprint::factory()->for($subject)->create();

        Http::fake([
            'qforge_python:8000/generate-questions' => Http::response([
                'status' => 'success',
                'data' => [['text' => 'Explain TCP congestion control.', 'type' => 'short', 'marks' => 5]],
                'errors' => [],
            ]),
            'qforge_python:8000/embed' => Http::response('', 500), // RAG down
        ]);

        $this->enableRag();
        Queue::fake(); // swallow the observer's sync dispatches

        (new ExpandQuestionBank($blueprint->id, [[
            'section_label' => 'A', 'type' => 'short', 'marks' => 5,
            'unit_ids' => [$unit->id], 'need' => 1,
        ]]))->handle(
            app(\App\Services\PythonService::class),
            app(\App\Services\AiExpansion\GroundingBuilder::class),
            app(\App\Services\Rag\DuplicateDetector::class),
        );

        // Text-only dedup still ran; the candidate landed despite the dead index.
        $this->assertSame(1, Question::where('subject_id', $subject->id)->where('source', 'ai')->count());
    }

    // ---- extraction review-queue flags ------------------------------------------

    public function test_extracted_candidate_gets_flagged_when_the_bank_holds_a_lookalike(): void
    {
        [$subject, $unit] = $this->bank();
        $upload = DocumentUpload::factory()->create(['subject_id' => $subject->id]);

        $this->enableRag();
        $this->fakeEmbed([[1, 0, 0]]);
        Http::fake([
            'qforge_qdrant:6333/collections/questions/points/search' => Http::response([
                'result' => [['id' => 321, 'score' => 0.93, 'payload' => []]],
            ]),
            // Phase 3 searches chunks for unit suggestions in the same pass.
            'qforge_qdrant:6333/collections/chunks/points/search' => Http::response(['result' => []]),
        ]);

        app(CandidateImporter::class)->import($upload, [
            ['text' => 'Explain TCP congestion control.', 'type' => 'short', 'marks' => 5, 'unit_hint' => 'Unit 1'],
        ]);

        $candidate = Question::where('source', 'extracted')->firstOrFail();
        $this->assertSame('pending', $candidate->status); // flagged, never blocked
        $this->assertSame(321, $candidate->attributes['similar']['question_id']);
        $this->assertSame(0.93, $candidate->attributes['similar']['score']);
    }

    public function test_extracted_candidate_below_threshold_is_not_flagged(): void
    {
        [$subject, $unit] = $this->bank();
        $upload = DocumentUpload::factory()->create(['subject_id' => $subject->id]);

        $this->enableRag();
        $this->fakeEmbed([[1, 0, 0]]);
        Http::fake([
            'qforge_qdrant:6333/collections/questions/points/search' => Http::response([
                'result' => [['id' => 321, 'score' => 0.62, 'payload' => []]],
            ]),
            'qforge_qdrant:6333/collections/chunks/points/search' => Http::response(['result' => []]),
        ]);

        app(CandidateImporter::class)->import($upload, [
            ['text' => 'What is a three-way handshake?', 'type' => 'short', 'marks' => 5, 'unit_hint' => 'Unit 1'],
        ]);

        $this->assertArrayNotHasKey('similar', Question::where('source', 'extracted')->firstOrFail()->attributes ?? []);
    }

    // ---- exact-text duplicate flags (independent of embeddings) -----------------

    public function test_exact_duplicate_of_an_approved_question_is_flagged_not_skipped(): void
    {
        [$subject, $unit] = $this->bank();
        $existing = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'status' => 'approved', 'source' => 'manual', 'text' => 'What is non-repudiation?',
        ]);
        $upload = DocumentUpload::factory()->create(['subject_id' => $subject->id]);

        // RAG stays off (default) — this is the fingerprint path, no embeddings.
        $counts = app(CandidateImporter::class)->import($upload, [
            ['text' => 'What is non-repudiation?', 'type' => 'short', 'marks' => 5, 'unit_hint' => 'Unit 1'],
        ]);

        $this->assertSame(1, $counts['created']);
        $this->assertSame(1, $counts['duplicate']);
        $this->assertSame(0, $counts['skipped']);

        $candidate = Question::where('source', 'extracted')->firstOrFail();
        $this->assertSame('pending', $candidate->status); // flagged, never dropped
        $this->assertSame(
            [['question_id' => $existing->id, 'status' => 'approved']],
            $candidate->attributes['duplicate_of'],
        );
    }

    public function test_exact_duplicate_of_only_a_rejected_question_is_not_flagged(): void
    {
        [$subject, $unit] = $this->bank();
        Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'status' => 'rejected', 'source' => 'manual', 'text' => 'Explain the SET protocol.',
        ]);
        $upload = DocumentUpload::factory()->create(['subject_id' => $subject->id]);

        $counts = app(CandidateImporter::class)->import($upload, [
            ['text' => 'Explain the SET protocol.', 'type' => 'short', 'marks' => 5, 'unit_hint' => 'Unit 1'],
        ]);

        // Rejected rows are excluded from the pool: the paper re-imports clean.
        $this->assertSame(1, $counts['created']);
        $this->assertSame(0, $counts['duplicate']);
        $this->assertArrayNotHasKey('duplicate_of', Question::where('source', 'extracted')->firstOrFail()->attributes ?? []);
    }

    public function test_exact_repeat_within_one_upload_is_collapsed(): void
    {
        [$subject, $unit] = $this->bank();
        $upload = DocumentUpload::factory()->create(['subject_id' => $subject->id]);

        $counts = app(CandidateImporter::class)->import($upload, [
            ['text' => 'Define page rank.', 'type' => 'short', 'marks' => 5, 'unit_hint' => 'Unit 1'],
            ['text' => 'Define page rank.', 'type' => 'short', 'marks' => 5, 'unit_hint' => 'Unit 1'],
        ]);

        $this->assertSame(1, $counts['created']);
        $this->assertSame(1, $counts['skipped']);   // the second copy, collapsed
        $this->assertSame(0, $counts['duplicate']);  // nothing pre-existing to flag
        $this->assertSame(1, Question::where('source', 'extracted')->count());
    }

    // ---- reindex ----------------------------------------------------------------

    public function test_reindex_embeds_only_the_approved_bank(): void
    {
        [$subject, $unit] = $this->bank();
        $approved = Question::factory()->count(2)->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'status' => 'approved',
        ]);
        Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'status' => 'pending',
        ]);

        $this->enableRag();
        $this->fakeEmbed([[0.1], [0.2]]);
        Http::fake([
            'qforge_qdrant:6333/readyz' => Http::response('ok'),
            'qforge_qdrant:6333/collections/*/exists' => Http::response(['result' => ['exists' => true]]),
            'qforge_qdrant:6333/*' => Http::response(['result' => true]),
        ]);

        $this->artisan('qforge:rag:reindex')
            ->expectsOutputToContain('Indexed 2 approved questions.')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/collections/questions/points')
            && count($request['points']) === 2
            && collect($request['points'])->pluck('id')->sort()->values()->all()
                === $approved->pluck('id')->sort()->values()->all());
    }

    // ---- the geometry helper ------------------------------------------------------

    public function test_cosine_similarity_behaves(): void
    {
        $this->assertEqualsWithDelta(1.0, VectorMath::cosine([1, 2, 3], [2, 4, 6]), 1e-9); // same direction
        $this->assertEqualsWithDelta(0.0, VectorMath::cosine([1, 0], [0, 1]), 1e-9);       // orthogonal
        $this->assertEqualsWithDelta(-1.0, VectorMath::cosine([1, 0], [-1, 0]), 1e-9);     // opposite
    }
}
