<?php

namespace App\Observers;

use App\Jobs\SyncSubjectChunks;
use App\Models\Subject;
use App\Models\Unit;

/**
 * Mirrors course-material writes into the Qdrant chunk index (M6 Phase 2).
 *
 * One observer for both corpus sources — `subjects.syllabus` and
 * `units.content` — because they land in the same job either way. Same shape
 * as QuestionObserver: this only answers "did retrievable content change?";
 * the queued job (unique per subject, so bursts collapse) does the work.
 */
class ContentObserver
{
    /**
     * Separate created/updated hooks (not one `saved`) because
     * `wasRecentlyCreated` stays true on an instance for its whole lifetime —
     * a later save on the same object would re-trigger the creation branch.
     */
    public function created(Subject|Unit $model): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        // Only when the new row actually carries corpus text — most units are
        // created bare and get content via a later update or syllabus import.
        if (trim((string) $this->corpus($model)) !== '') {
            SyncSubjectChunks::dispatch($this->subjectIdOf($model));
        }
    }

    public function updated(Subject|Unit $model): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        $field = $model instanceof Subject ? 'syllabus' : 'content';

        if ($model->wasChanged($field)) {
            SyncSubjectChunks::dispatch($this->subjectIdOf($model));
        }
    }

    public function deleted(Subject|Unit $model): void
    {
        if (! config('services.qdrant.enabled')) {
            return;
        }

        if ($model instanceof Unit) {
            // The unit's chunk rows cascaded away; rebuild so Qdrant follows.
            SyncSubjectChunks::dispatch((int) $model->subject_id);
        }
        // A deleted Subject needs no rebuild — Phase 2 keeps this simple and
        // lets `qforge:rag:reindex --fresh` collect orphaned points.
    }

    private function corpus(Subject|Unit $model): ?string
    {
        return $model instanceof Subject ? $model->syllabus : $model->content;
    }

    private function subjectIdOf(Subject|Unit $model): int
    {
        return (int) ($model instanceof Subject ? $model->id : $model->subject_id);
    }
}
