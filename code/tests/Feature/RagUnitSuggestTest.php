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
 * M6 Phase 3 — unit auto-suggest in the review queue (docs/RAG-GUIDE.md).
 *
 * Suggestions come from searching the candidate's vector against the chunk
 * index and keeping each unit's best score. Flag-only: they pre-fill the
 * review form, never auto-assign (unit tags stay human-assigned, per Post-M5).
 */
class RagUnitSuggestTest extends TestCase
{
    use RefreshDatabase;

    private function importCandidate(array $chunkHits, array $questionHits = []): Question
    {
        $subject = Subject::factory()->create(['syllabus' => '# Networks']);
        Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);
        $upload = DocumentUpload::factory()->create(['subject_id' => $subject->id]);

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
            ['text' => 'Explain how routers pick the best path.', 'type' => 'short', 'marks' => 5],
        ]);

        return Question::where('source', 'extracted')->firstOrFail();
    }

    public function test_suggests_units_ranked_by_their_best_chunk(): void
    {
        $candidate = $this->importCandidate([
            ['id' => 1, 'score' => 0.71, 'payload' => ['unit_id' => 3]],
            ['id' => 2, 'score' => 0.68, 'payload' => ['unit_id' => 7]],
            ['id' => 3, 'score' => 0.64, 'payload' => ['unit_id' => 3]],  // weaker chunk of unit 3 — ignored (max wins)
            ['id' => 4, 'score' => 0.62, 'payload' => ['unit_id' => null]], // syllabus chunk — skipped
            ['id' => 5, 'score' => 0.31, 'payload' => ['unit_id' => 9]],  // below the 0.50 floor
        ]);

        $this->assertSame(
            [['unit_id' => 3, 'score' => 0.71], ['unit_id' => 7, 'score' => 0.68]],
            $candidate->attributes['suggested_units'],
        );
        $this->assertArrayHasKey('rag_checked_at', $candidate->attributes);
        $this->assertSame('pending', $candidate->status); // suggest-only, never assigns
        $this->assertNull($candidate->unit_id);
    }

    public function test_no_suggestions_when_nothing_clears_the_floor(): void
    {
        $candidate = $this->importCandidate([
            ['id' => 1, 'score' => 0.42, 'payload' => ['unit_id' => 3]],
        ]);

        $this->assertArrayNotHasKey('suggested_units', $candidate->attributes ?? []);
    }

    public function test_similar_flag_and_suggestions_land_in_one_annotation(): void
    {
        $candidate = $this->importCandidate(
            chunkHits: [['id' => 1, 'score' => 0.66, 'payload' => ['unit_id' => 3]]],
            questionHits: [['id' => 42, 'score' => 0.95, 'payload' => []]],
        );

        $this->assertSame(42, $candidate->attributes['similar']['question_id']);
        $this->assertSame([['unit_id' => 3, 'score' => 0.66]], $candidate->attributes['suggested_units']);
    }
}
