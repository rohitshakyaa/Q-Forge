<?php

namespace App\Jobs;

use App\Models\DocumentUpload;
use App\Services\Extraction\CandidateImporter;
use App\Services\PythonService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands an uploaded document to Python `/extract` and persists what comes back.
 *
 * Queued because OCR over a scanned paper takes minutes, not milliseconds.
 */
class ProcessDocumentUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** OCR is slow; give the whole job room beyond the HTTP timeout. */
    public int $timeout = 900;

    public function __construct(public readonly int $uploadId) {}

    /** Exponential-ish backoff: a wedged Python service benefits from a pause. */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(PythonService $python, CandidateImporter $importer): void
    {
        $upload = DocumentUpload::find($this->uploadId);

        if ($upload === null || $upload->isTerminal()) {
            return; // Deleted, or already processed by an earlier attempt.
        }

        $upload->update(['status' => DocumentUpload::STATUS_PROCESSING, 'error' => null]);

        $data = $python->extract($upload->pythonPath(), $upload->type);
        $counts = $importer->import($upload, $data['candidates'] ?? []);

        $upload->update([
            'status' => DocumentUpload::STATUS_PARSED,
            'error' => null,
            'meta' => array_merge($upload->meta ?? [], [
                'pages' => $data['pages'] ?? null,
                'ocr_pages' => $data['ocr_pages'] ?? null,
                'questions_created' => $counts['created'],
                'questions_skipped' => $counts['skipped'],
                'questions_unlinked' => $counts['unlinked'],
                // A syllabus creates nothing on its own: the parsed courses wait here
                // until an admin confirms them on the import screen.
                'courses' => $data['courses'] ?? [],
            ]),
        ]);
    }

    /**
     * Record why extraction failed so the admin sees it instead of a stuck spinner.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessDocumentUpload failed', [
            'upload_id' => $this->uploadId,
            'exception' => $exception->getMessage(),
        ]);

        DocumentUpload::where('id', $this->uploadId)->update([
            'status' => DocumentUpload::STATUS_FAILED,
            'error' => $exception->getMessage(),
        ]);
    }
}
