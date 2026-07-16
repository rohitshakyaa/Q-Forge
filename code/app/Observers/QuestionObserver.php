<?php

namespace App\Observers;

use App\Jobs\SyncQuestionEmbedding;
use App\Models\Question;

/**
 * Mirrors question writes into the Qdrant vector index (M6 Phase 1).
 *
 * One choke point instead of dispatches sprinkled across controllers/jobs:
 * any path that saves or deletes a Question — create, review approve/reject,
 * edit, AI expansion, seeding — triggers a queued sync. The job itself decides
 * upsert vs delete from current DB state, so this observer only answers "did
 * anything index-relevant change?".
 *
 * Caveat inherited from Eloquent: bulk `Question::where(...)->update(...)`
 * fires no model events. The review queue's bulkReject uses that — fine, since
 * pending candidates were never indexed — but any future bulk write that touches
 * *approved* rows must dispatch SyncQuestionEmbedding itself (or be followed by
 * `qforge:rag:reindex`).
 */
class QuestionObserver
{
    /**
     * Separate created/updated hooks (not one `saved`) because
     * `wasRecentlyCreated` stays true on an instance for its whole lifetime —
     * a later save on the same object would re-trigger the creation branch.
     */
    public function created(Question $question): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        if ($question->status === 'approved') {
            SyncQuestionEmbedding::dispatch($question->id);
        }
    }

    public function updated(Question $question): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        // Which changes matter: text is what gets embedded; status moves the
        // question in/out of the indexed (approved) pool; the rest is payload
        // that dedup/exemplar searches filter on.
        $relevant = ['text', 'status', 'subject_id', 'unit_id', 'type', 'marks'];

        if ($question->status === 'approved') {
            if ($question->wasChanged($relevant)) {
                SyncQuestionEmbedding::dispatch($question->id);
            }
        } elseif ($question->wasChanged('status')) {
            // Left the approved pool — the job will see the status and delete.
            SyncQuestionEmbedding::dispatch($question->id);
        }
    }

    public function deleted(Question $question): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        // The job finds no row and removes the point.
        SyncQuestionEmbedding::dispatch($question->id);
    }
}
