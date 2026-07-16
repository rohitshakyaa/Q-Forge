<?php

namespace App\Jobs;

use App\Models\Subject;
use App\Services\Rag\ContentIndexer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-chunks and re-indexes one subject's course material (M6 Phase 2).
 *
 * ShouldBeUnique is the debounce: a syllabus import saves every unit in quick
 * succession and each save queues this job, but only one instance per subject
 * may wait on the queue — the rest collapse into it. Wholesale rebuild makes
 * that safe: whenever the survivor runs, it reads current state.
 */
class SyncSubjectChunks implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    /** Embedding a whole subject's corpus in one batch can take a while on CPU. */
    public int $timeout = 300;

    public function __construct(public readonly int $subjectId)
    {
        // Don't index ahead of an uncommitted transaction the worker could outrun.
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->subjectId;
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ContentIndexer $indexer): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        $subject = Subject::find($this->subjectId);

        if ($subject === null) {
            return; // Deleted since queueing; its chunk rows cascaded away with it.
        }

        $indexed = $indexer->sync($subject);

        Log::debug('SyncSubjectChunks: indexed', [
            'subject' => $subject->id, 'chunks' => $indexed,
        ]);
    }
}
