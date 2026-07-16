<?php

namespace App\Jobs;

use App\Models\Question;
use App\Services\PythonService;
use App\Services\Rag\QdrantClient;
use App\Services\Rag\QuestionPoints;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Keeps one question's vector in Qdrant in sync with MySQL (M6 Phase 1 —
 * docs/RAG-GUIDE.md).
 *
 * Idempotent and self-deciding: it loads the question fresh and either upserts
 * (approved — the only pool dedup searches) or deletes its point (rejected,
 * back to pending, or gone entirely). Callers never choose; re-running is
 * always safe, and a stale queue entry converges on the current DB state.
 */
class SyncQuestionEmbedding implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $questionId)
    {
        // Don't index ahead of an uncommitted transaction the worker could outrun.
        $this->afterCommit();
    }

    /** Embedding is quick; Qdrant hiccups deserve a pause before retrying. */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(PythonService $python, QdrantClient $qdrant): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        $question = Question::with('units')->find($this->questionId);

        if ($question === null || $question->status !== 'approved') {
            // Left the approved pool (or never reached it): its vector must not
            // keep matching dedup/exemplar searches. Deleting a missing point is
            // a no-op, so this needs no existence check.
            $qdrant->deletePoints(QdrantClient::COLLECTION_QUESTIONS, [$this->questionId]);

            return;
        }

        $embedded = $python->embed([$question->text]);

        $qdrant->upsert(QdrantClient::COLLECTION_QUESTIONS, [
            QuestionPoints::make($question, $embedded['embeddings'][0], $embedded['model']),
        ]);

        Log::debug('SyncQuestionEmbedding: indexed', [
            'question' => $question->id, 'model' => $embedded['model'],
        ]);
    }
}
