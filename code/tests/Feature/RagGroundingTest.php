<?php

namespace Tests\Feature;

use App\Jobs\SyncSubjectChunks;
use App\Models\ContentChunk;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Services\AiExpansion\GroundingBuilder;
use App\Services\Rag\ContentChunker;
use App\Services\Rag\ContentIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * M6 Phase 2 — retrieval-augmented grounding (docs/RAG-GUIDE.md).
 *
 * Covers the chunker's splitting rules, the chunk indexing pipeline
 * (observer → job → rows + points), and GroundingBuilder's budget-based
 * hybrid: full content under budget, top-k retrieved chunks over it,
 * semantic exemplars whenever RAG is up, M5 behaviour whenever it isn't.
 */
class RagGroundingTest extends TestCase
{
    use RefreshDatabase;

    private function enableRag(): void
    {
        config(['services.qdrant.enabled' => true]);
    }

    // ---- the chunker -------------------------------------------------------------

    public function test_chunker_splits_on_headings_and_carries_the_context_path(): void
    {
        $chunks = (new ContentChunker)->chunk(
            "intro paragraph before any heading, long enough to stand on its own as a chunk of retrievable text once the minimum size rule is applied to it, which needs a couple of hundred characters of body to be satisfied here.\n\n"
            ."## Flow control\n\n".str_repeat('Sliding windows manage sender pace. ', 12)."\n\n"
            ."## Congestion control\n\n".str_repeat('AIMD probes for available bandwidth. ', 12),
            'CS301 > Unit 3'
        );

        $this->assertCount(3, $chunks);
        $this->assertSame('CS301 > Unit 3', $chunks[0]['heading']); // pre-heading text
        $this->assertSame('CS301 > Unit 3 > Flow control', $chunks[1]['heading']);
        $this->assertSame('CS301 > Unit 3 > Congestion control', $chunks[2]['heading']);
        $this->assertStringContainsString('Sliding windows', $chunks[1]['text']);
    }

    public function test_chunker_packs_long_sections_without_splitting_paragraphs(): void
    {
        $paragraph = str_repeat('Routers forward packets toward their destination. ', 12); // ~600 chars
        $chunks = (new ContentChunker)->chunk(
            "## Routing\n\n{$paragraph}\n\n{$paragraph}\n\n{$paragraph}\n\n{$paragraph}",
            'CS301 > Unit 2'
        );

        $this->assertGreaterThan(1, count($chunks)); // 4 × ~600 chars can't fit one 1600-char chunk
        foreach ($chunks as $chunk) {
            // No chunk exceeds target by more than one whole paragraph, and none
            // contains a severed paragraph (each starts exactly like the source).
            $this->assertLessThanOrEqual(ContentChunker::TARGET_CHARS + 700, mb_strlen($chunk['text']));
            $this->assertStringStartsWith('Routers forward', $chunk['text']);
        }
    }

    public function test_chunker_merges_runts_and_skips_empty_content(): void
    {
        $this->assertSame([], (new ContentChunker)->chunk('', 'CS301'));
        $this->assertSame([], (new ContentChunker)->chunk("   \n\n  ", 'CS301'));

        // A tiny trailing section folds into its neighbour instead of becoming a
        // near-empty vector that matches everything weakly.
        $chunks = (new ContentChunker)->chunk(
            '## Topic\n\n'.str_repeat('Body sentence. ', 30)."\n\nTiny.",
            'CS301'
        );
        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Tiny.', $chunks[0]['text']);
    }

    // ---- indexing pipeline --------------------------------------------------------

    private function subjectWithContent(): array
    {
        $subject = Subject::factory()->create([
            'code' => 'CS301',
            'syllabus' => "# Computer Networks\n\nA course about how networks work, from physical links to applications, covering the classic stack layer by layer with protocol case studies and worked examples throughout the semester.",
        ]);
        $unit = Unit::factory()->for($subject)->create([
            'name' => 'Transport Layer', 'position' => 1,
            'content' => "## TCP\n\n".str_repeat('Reliable, ordered, connection-oriented delivery with congestion control. ', 6),
        ]);

        return [$subject, $unit];
    }

    public function test_content_indexer_rebuilds_rows_and_points_for_a_subject(): void
    {
        [$subject, $unit] = $this->subjectWithContent();
        // A stale row from a previous sync: the rebuild must replace it.
        ContentChunk::create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'position' => 0, 'heading' => 'stale', 'text' => 'old text',
        ]);

        $this->enableRag();
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text', 'dimensions' => 2, 'embeddings' => [[0.1, 0.2], [0.3, 0.4]]],
                'errors' => [],
            ]),
            'qforge_qdrant:6333/*' => Http::response(['result' => true]),
        ]);

        $indexed = app(ContentIndexer::class)->sync($subject);

        $this->assertSame(2, $indexed); // one syllabus chunk + one unit chunk
        $this->assertSame(0, ContentChunk::where('heading', 'stale')->count());

        $rows = ContentChunk::where('subject_id', $subject->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->unit_id); // syllabus chunk is subject-level
        $this->assertSame($unit->id, $rows[1]->unit_id);
        $this->assertStringContainsString('CS301 > Transport Layer', $rows[1]->heading);

        // Old points cleared by payload filter, new ones upserted with unit payload.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/collections/chunks/points/delete')
            && $r['filter']['must'][0]['key'] === 'subject_id');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/collections/chunks/points?')
            && count($r['points']) === 2
            && $r['points'][0]['payload']['unit_id'] === null
            && $r['points'][1]['payload']['unit_id'] === $unit->id);
    }

    public function test_unit_content_change_queues_a_chunk_sync_and_bursts_collapse(): void
    {
        [$subject, $unit] = $this->subjectWithContent();

        $this->enableRag();
        Queue::fake();

        $unit->update(['position' => 2]); // not corpus — must not queue
        Queue::assertNothingPushed();

        $unit->update(['content' => "## TCP\n\nRewritten body."]);
        // Same-subject burst: ShouldBeUnique collapses this second dispatch into
        // the one already waiting — the syllabus import debounce in miniature.
        $subject->update(['syllabus' => '# Networks v2']);

        Queue::assertPushed(SyncSubjectChunks::class, 1);
        Queue::assertPushed(SyncSubjectChunks::class, fn ($job) => $job->subjectId === $subject->id);
    }

    // ---- the budget-based hybrid ----------------------------------------------------

    /** @return array{0: Subject, 1: Unit, 2: GroundingBuilder} */
    private function groundingSetup(): array
    {
        [$subject, $unit] = $this->subjectWithContent();

        return [$subject, $unit, app(GroundingBuilder::class)];
    }

    public function test_under_budget_sends_full_content_and_searches_no_chunks(): void
    {
        [$subject, $unit, $builder] = $this->groundingSetup();

        $this->enableRag();
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text', 'dimensions' => 2, 'embeddings' => [[1, 0]]],
                'errors' => [],
            ]),
            'qforge_qdrant:6333/*' => Http::response(['result' => []]),
        ]);

        $block = $builder->for($subject, [$unit], 'short', 5);

        // The whole syllabus and unit body went through untouched.
        $this->assertStringContainsString('# Course syllabus', $block['text']);
        $this->assertStringContainsString('classic stack layer by layer', $block['text']);
        $this->assertStringContainsString('# Unit: Transport Layer', $block['text']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/collections/chunks/points/search'));
    }

    public function test_over_budget_builds_the_block_from_retrieved_chunks(): void
    {
        [$subject, $unit, $builder] = $this->groundingSetup();
        $chunk = ContentChunk::create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id, 'position' => 0,
            'heading' => 'CS301 > Transport Layer > TCP',
            'text' => 'Reliable, ordered delivery with AIMD congestion control.',
        ]);

        $this->enableRag();
        config(['services.qdrant.grounding_budget_chars' => 50]); // force the retrieval path
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text', 'dimensions' => 2, 'embeddings' => [[1, 0]]],
                'errors' => [],
            ]),
            'qforge_qdrant:6333/collections/chunks/points/search' => Http::response([
                'result' => [['id' => $chunk->id, 'score' => 0.82, 'payload' => []]],
            ]),
            'qforge_qdrant:6333/*' => Http::response(['result' => []]),
        ]);

        $block = $builder->for($subject, [$unit], 'short', 5);

        // The block is the retrieved chunk under its context path — NOT the raw dump.
        $this->assertStringContainsString('# CS301 > Transport Layer > TCP', $block['text']);
        $this->assertStringContainsString('AIMD congestion control', $block['text']);
        $this->assertStringNotContainsString('# Course syllabus', $block['text']);
        $this->assertStringContainsString('built from top-1 retrieved chunks', implode('; ', $block['notes']));

        // The chunk search was scoped to the slot's subject AND target unit.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/collections/chunks/points/search')
            && $r['filter']['must'][0]['match']['value'] === $subject->id
            && $r['filter']['must'][1]['match']['any'] === [$unit->id]);
    }

    public function test_over_budget_with_nothing_indexed_falls_back_to_full_content(): void
    {
        [$subject, $unit, $builder] = $this->groundingSetup();

        $this->enableRag();
        config(['services.qdrant.grounding_budget_chars' => 50]);
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text', 'dimensions' => 2, 'embeddings' => [[1, 0]]],
                'errors' => [],
            ]),
            'qforge_qdrant:6333/*' => Http::response(['result' => []]), // no hits anywhere
        ]);

        $block = $builder->for($subject, [$unit], 'short', 5);

        $this->assertStringContainsString('# Course syllabus', $block['text']);
        $this->assertStringContainsString('over budget but no chunks indexed', implode('; ', $block['notes']));
    }

    public function test_semantic_exemplars_come_back_in_similarity_order(): void
    {
        [$subject, $unit, $builder] = $this->groundingSetup();
        $a = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'status' => 'approved', 'text' => 'Exemplar A about handshakes.',
        ]);
        $b = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'status' => 'approved', 'text' => 'Exemplar B about congestion.',
        ]);
        foreach ([$a, $b] as $q) {
            $q->syncUnitLinks();
        }

        $this->enableRag();
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text', 'dimensions' => 2, 'embeddings' => [[1, 0]]],
                'errors' => [],
            ]),
            // Qdrant ranks B above A — SQL id-order would say A first.
            'qforge_qdrant:6333/collections/questions/points/search' => Http::response([
                'result' => [
                    ['id' => $b->id, 'score' => 0.9, 'payload' => []],
                    ['id' => $a->id, 'score' => 0.7, 'payload' => []],
                ],
            ]),
            'qforge_qdrant:6333/*' => Http::response(['result' => []]),
        ]);

        $block = $builder->for($subject, [$unit], 'short', 5);

        $this->assertMatchesRegularExpression(
            '/1\. Exemplar B about congestion\..*2\. Exemplar A about handshakes\./s',
            $block['text'],
        );

        // The exemplar search filters by subject + type + unit tags.
        Http::assertSent(function ($r) use ($subject, $unit) {
            if (! str_contains($r->url(), '/collections/questions/points/search')) {
                return false;
            }
            $keys = array_column($r['filter']['must'], 'key');

            return $keys === ['subject_id', 'type', 'unit_ids']
                && $r['filter']['must'][2]['match']['any'] === [$unit->id];
        });
    }

    public function test_rag_down_degrades_to_m5_grounding_exactly(): void
    {
        [$subject, $unit, $builder] = $this->groundingSetup();
        $q = Question::factory()->create([
            'subject_id' => $subject->id, 'unit_id' => $unit->id,
            'type' => 'short', 'status' => 'approved', 'text' => 'SQL-order exemplar.',
        ]);
        $q->syncUnitLinks();

        $this->enableRag();
        Http::fake(['qforge_python:8000/embed' => Http::response('', 500)]);

        $block = $builder->for($subject, [$unit], 'short', 5);

        $this->assertStringContainsString('# Course syllabus', $block['text']);
        $this->assertStringContainsString('SQL-order exemplar.', $block['text']);
        $this->assertStringContainsString('RAG unavailable', implode('; ', $block['notes']));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'qdrant'));
    }

    // ---- reindex -------------------------------------------------------------------

    public function test_reindex_reports_chunk_counts(): void
    {
        [$subject, $unit] = $this->subjectWithContent();

        $this->enableRag();
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text', 'dimensions' => 2, 'embeddings' => [[0.1], [0.2]]],
                'errors' => [],
            ]),
            'qforge_qdrant:6333/readyz' => Http::response('ok'),
            'qforge_qdrant:6333/collections/*/exists' => Http::response(['result' => ['exists' => true]]),
            'qforge_qdrant:6333/*' => Http::response(['result' => true]),
        ]);

        $this->artisan('qforge:rag:reindex')
            ->expectsOutputToContain('Indexed 2 content chunks.')
            ->assertSuccessful();

        $this->assertSame(2, ContentChunk::where('subject_id', $subject->id)->count());
    }
}
