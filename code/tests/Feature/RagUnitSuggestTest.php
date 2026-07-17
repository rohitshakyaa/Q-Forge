<?php

namespace Tests\Feature;

use App\Models\DocumentUpload;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Services\Extraction\CandidateImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M6 Phase 3 + 3b — unit auto-suggest & auto-assign in the review queue
 * (docs/RAG-GUIDE.md).
 *
 * Suggestions come from searching the candidate's vector against the chunk
 * index and keeping each unit's best score. When the parser found no unit and
 * the top suggestion clears `unit_auto_assign_threshold`, the candidate is
 * pre-tagged with that unit as primary (`attributes.unit_auto_assigned`) —
 * still pending, still the human's call at approval. A parser-resolved unit
 * is never overridden.
 */
class RagUnitSuggestTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    /** @var Unit[] three real units of $subject, positions 1–3 */
    private array $units;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = Subject::factory()->create(['syllabus' => '# Networks']);
        $this->units = [
            Unit::factory()->for($this->subject)->create(['name' => 'Unit 1', 'position' => 1]),
            Unit::factory()->for($this->subject)->create(['name' => 'Unit 2', 'position' => 2]),
            Unit::factory()->for($this->subject)->create(['name' => 'Unit 3', 'position' => 3]),
        ];
    }

    private function importCandidate(array $chunkHits, array $questionHits = [], array $candidate = []): Question
    {
        $upload = DocumentUpload::factory()->create(['subject_id' => $this->subject->id]);

        config(['services.qdrant.enabled' => true]);
        Http::fake([
            'qforge_python:8000/embed' => Http::response([
                'status' => 'success',
                'data' => ['model' => 'nomic-embed-text', 'dimensions' => 2, 'embeddings' => [[1, 0]]],
                'errors' => [],
            ]),
            'qforge_qdrant:6333/collections/chunks/points/search' => Http::response(['result' => $chunkHits]),
            'qforge_qdrant:6333/collections/questions/points/search' => Http::response(['result' => $questionHits]),
        ]);

        app(CandidateImporter::class)->import($upload, [
            array_merge(['text' => 'Explain how routers pick the best path.', 'type' => 'short', 'marks' => 5], $candidate),
        ]);

        return Question::where('source', 'extracted')->firstOrFail();
    }

    public function test_suggests_units_ranked_by_their_best_chunk_and_auto_assigns_the_top(): void
    {
        [$u1, $u2, $u3] = $this->units;

        $candidate = $this->importCandidate([
            ['id' => 1, 'score' => 0.71, 'payload' => ['unit_id' => $u1->id]],
            ['id' => 2, 'score' => 0.68, 'payload' => ['unit_id' => $u2->id]],
            ['id' => 3, 'score' => 0.64, 'payload' => ['unit_id' => $u1->id]],  // weaker chunk of unit 1 — ignored (max wins)
            ['id' => 4, 'score' => 0.62, 'payload' => ['unit_id' => null]],     // syllabus chunk — skipped
            ['id' => 5, 'score' => 0.31, 'payload' => ['unit_id' => $u3->id]],  // below the 0.50 floor
        ]);

        $this->assertSame(
            [['unit_id' => $u1->id, 'score' => 0.71], ['unit_id' => $u2->id, 'score' => 0.68]],
            $candidate->attributes['suggested_units'],
        );
        $this->assertArrayHasKey('rag_checked_at', $candidate->attributes);

        // Phase 3b: the top suggestion cleared the threshold — pre-tagged as primary.
        $this->assertSame($u1->id, $candidate->unit_id);
        $this->assertSame(
            ['unit_id' => $u1->id, 'score' => 0.71],
            $candidate->attributes['unit_auto_assigned'],
        );
        $this->assertSame([$u1->id], $candidate->units()->pluck('units.id')->all()); // pivot mirrored
        $this->assertSame('pending', $candidate->status); // pre-tagged, never pre-approved
    }

    public function test_no_suggestions_when_nothing_clears_the_floor(): void
    {
        $candidate = $this->importCandidate([
            ['id' => 1, 'score' => 0.42, 'payload' => ['unit_id' => $this->units[0]->id]],
        ]);

        $this->assertArrayNotHasKey('suggested_units', $candidate->attributes ?? []);
        $this->assertArrayNotHasKey('unit_auto_assigned', $candidate->attributes ?? []);
        $this->assertNull($candidate->unit_id);
    }

    public function test_parser_resolved_unit_is_never_overridden(): void
    {
        [$u1, $u2] = $this->units;

        // The heading said Unit 2; RAG prefers unit 1. The heading wins.
        $candidate = $this->importCandidate(
            chunkHits: [['id' => 1, 'score' => 0.95, 'payload' => ['unit_id' => $u1->id]]],
            candidate: ['unit_hint' => 'Unit 2'],
        );

        $this->assertSame($u2->id, $candidate->unit_id);
        $this->assertSame([['unit_id' => $u1->id, 'score' => 0.95]], $candidate->attributes['suggested_units']);
        $this->assertArrayNotHasKey('unit_auto_assigned', $candidate->attributes);
    }

    public function test_no_auto_assign_when_the_cited_unit_is_not_this_subjects(): void
    {
        $foreign = Unit::factory()->create(); // another subject's unit — stale chunk index

        $candidate = $this->importCandidate([
            ['id' => 1, 'score' => 0.88, 'payload' => ['unit_id' => $foreign->id]],
        ]);

        $this->assertNull($candidate->unit_id);
        $this->assertArrayNotHasKey('unit_auto_assigned', $candidate->attributes);
    }

    public function test_auto_assign_threshold_can_demand_more_than_the_suggestion_floor(): void
    {
        config(['services.qdrant.unit_auto_assign_threshold' => 0.80]);

        $candidate = $this->importCandidate([
            ['id' => 1, 'score' => 0.71, 'payload' => ['unit_id' => $this->units[0]->id]],
        ]);

        // Suggested (≥ 0.50) but not assigned (< 0.80).
        $this->assertSame([['unit_id' => $this->units[0]->id, 'score' => 0.71]], $candidate->attributes['suggested_units']);
        $this->assertNull($candidate->unit_id);
        $this->assertArrayNotHasKey('unit_auto_assigned', $candidate->attributes);
    }

    public function test_similar_flag_and_suggestions_land_in_one_annotation(): void
    {
        $u1 = $this->units[0];

        $candidate = $this->importCandidate(
            chunkHits: [['id' => 1, 'score' => 0.66, 'payload' => ['unit_id' => $u1->id]]],
            questionHits: [['id' => 42, 'score' => 0.95, 'payload' => []]],
        );

        $this->assertSame(42, $candidate->attributes['similar']['question_id']);
        $this->assertSame([['unit_id' => $u1->id, 'score' => 0.66]], $candidate->attributes['suggested_units']);
        $this->assertSame($u1->id, $candidate->unit_id);
    }

    public function test_backfill_command_assigns_from_stored_suggestions(): void
    {
        [$u1] = $this->units;

        $stale = Question::create([
            'subject_id' => $this->subject->id,
            'unit_id' => null,
            'type' => 'short',
            'marks' => 5,
            'text' => 'Imported before the auto-assign rule existed.',
            'source' => 'extracted',
            'status' => 'pending',
            'attributes' => ['suggested_units' => [['unit_id' => $u1->id, 'score' => 0.71]]],
        ]);

        $this->artisan('qforge:rag:auto-assign-units')
            ->expectsOutputToContain('assigned 1')
            ->assertSuccessful();

        $stale->refresh();
        $this->assertSame($u1->id, $stale->unit_id);
        $this->assertSame(['unit_id' => $u1->id, 'score' => 0.71], $stale->attributes['unit_auto_assigned']);
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $stale = Question::create([
            'subject_id' => $this->subject->id,
            'unit_id' => null,
            'type' => 'short',
            'marks' => 5,
            'text' => 'Preview me.',
            'source' => 'extracted',
            'status' => 'pending',
            'attributes' => ['suggested_units' => [['unit_id' => $this->units[0]->id, 'score' => 0.71]]],
        ]);

        $this->artisan('qforge:rag:auto-assign-units', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($stale->refresh()->unit_id);
    }
}
