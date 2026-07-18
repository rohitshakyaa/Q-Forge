<?php

namespace Tests\Unit\PaperGeneration;

use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Services\PaperGeneration\VectorSimilarityGuard;
use App\Services\Rag\QdrantClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VectorSimilarityGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, Question> two persisted questions [a, b] */
    private function twoQuestions(): array
    {
        $subject = Subject::factory()->create();
        $unit = Unit::factory()->for($subject)->create(['name' => 'Unit 1', 'position' => 1]);

        return Question::factory()->count(2)->create([
            'subject_id' => $subject->id,
            'unit_id' => $unit->id,
            'type' => 'short',
            'marks' => 4,
            'status' => 'approved',
        ])->values()->all();
    }

    public function test_fails_open_when_rag_is_disabled(): void
    {
        config()->set('services.qdrant.enabled', false);
        Http::fake(); // Assert nothing is even attempted.

        [$a, $b] = $this->twoQuestions();
        $guard = new VectorSimilarityGuard(new QdrantClient);

        $guard->prime(collect([$a, $b]));
        $this->assertFalse($guard->tooSimilar($a, [$b]));

        Http::assertNothingSent();
    }

    public function test_flags_near_duplicates_by_cosine_over_the_threshold(): void
    {
        // Seed the bank while RAG is off so the embedding observers stay quiet;
        // enable it only for the guard under test.
        [$a, $b] = $this->twoQuestions();

        config()->set('services.qdrant.enabled', true);
        config()->set('services.qdrant.duplicate_threshold', 0.90);

        // a and b share the same direction (cosine 1.0); a third would be orthogonal.
        Http::fake([
            '*/collections/questions/points' => Http::response([
                'result' => [
                    ['id' => $a->id, 'vector' => [1.0, 0.0, 0.0]],
                    ['id' => $b->id, 'vector' => [1.0, 0.0, 0.0]],
                ],
            ]),
            '*' => Http::response(['result' => []]),
        ]);

        $guard = new VectorSimilarityGuard(new QdrantClient);
        $guard->prime(collect([$a, $b]));

        $this->assertTrue($guard->tooSimilar($a, [$b]));
    }

    public function test_distinct_directions_are_not_duplicates(): void
    {
        [$a, $b] = $this->twoQuestions();

        config()->set('services.qdrant.enabled', true);
        config()->set('services.qdrant.duplicate_threshold', 0.90);

        Http::fake([
            '*/collections/questions/points' => Http::response([
                'result' => [
                    ['id' => $a->id, 'vector' => [1.0, 0.0, 0.0]],
                    ['id' => $b->id, 'vector' => [0.0, 1.0, 0.0]], // orthogonal → cosine 0
                ],
            ]),
            '*' => Http::response(['result' => []]),
        ]);

        $guard = new VectorSimilarityGuard(new QdrantClient);
        $guard->prime(collect([$a, $b]));

        $this->assertFalse($guard->tooSimilar($a, [$b]));
    }

    public function test_fails_open_when_the_index_errors(): void
    {
        [$a, $b] = $this->twoQuestions();

        config()->set('services.qdrant.enabled', true);

        Http::fake([
            '*/collections/questions/points' => Http::response(null, 500),
            '*' => Http::response(['result' => []]),
        ]);

        $guard = new VectorSimilarityGuard(new QdrantClient);
        $guard->prime(collect([$a, $b])); // swallows the failure, degrades to no-op

        $this->assertFalse($guard->tooSimilar($a, [$b]));
    }
}
