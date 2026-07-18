<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Models\Subject;
use App\Services\PythonService;
use App\Services\Rag\ContentIndexer;
use App\Services\Rag\QdrantClient;
use App\Services\Rag\QuestionPoints;
use Illuminate\Console\Command;

/**
 * Rebuild the Qdrant vector index from MySQL (M6 — RAG, docs/RAG-GUIDE.md).
 *
 * This command is what makes Qdrant an index rather than a database: everything
 * in it is derived (text → embedding), so losing the volume, corrupting a
 * collection, or swapping the embedding model is always recoverable from the
 * source of truth.
 *
 * Phase 1 indexes the approved question bank; Phase 2 re-chunks and re-indexes
 * every subject's course material.
 */
class RagReindex extends Command
{
    /** Texts per /embed round-trip — big enough to amortise HTTP, small enough to watch progress. */
    private const BATCH = 64;

    protected $signature = 'qforge:rag:reindex
        {--fresh : Drop and recreate the collections before re-indexing (required after an embedding-model swap)}';

    protected $description = 'Rebuild the Qdrant vector index (collections + embeddings) from MySQL';

    public function handle(QdrantClient $qdrant, PythonService $python, ContentIndexer $content): int
    {
        if (! $qdrant->healthy()) {
            $this->error('Qdrant is unreachable at '.config('services.qdrant.base_url').' — is the qforge_qdrant container up?');

            return self::FAILURE;
        }

        $collections = [QdrantClient::COLLECTION_QUESTIONS, QdrantClient::COLLECTION_CHUNKS];

        foreach ($collections as $collection) {
            if ($this->option('fresh')) {
                $qdrant->recreateCollection($collection);
                $this->info("Recreated collection '{$collection}'.");
            } else {
                $qdrant->ensureCollection($collection);
                $this->info("Ensured collection '{$collection}' exists.");
            }
        }

        $indexed = $this->reindexQuestions($qdrant, $python);
        $this->info("Indexed {$indexed} approved questions.");

        $chunks = 0;
        foreach (Subject::query()->orderBy('id')->cursor() as $subject) {
            $chunks += $content->sync($subject);
        }
        $this->info("Indexed {$chunks} content chunks.");

        return self::SUCCESS;
    }

    /**
     * Re-embed the whole approved bank, batched. Only `approved` rows: they are
     * the single pool dedup/exemplar searches run against (pending candidates
     * are annotated at extraction time, not indexed).
     */
    private function reindexQuestions(QdrantClient $qdrant, PythonService $python): int
    {
        $indexed = 0;
        $bar = $this->output->createProgressBar(Question::where('status', 'approved')->count());

        Question::with('units')
            ->where('status', 'approved')
            ->chunkById(self::BATCH, function ($questions) use ($qdrant, $python, &$indexed, $bar) {
                $embedded = $python->embed($questions->map(fn (Question $q) => (string) $q->text)->all(), 'document');

                $points = $questions->values()->map(
                    fn (Question $q, int $i) => QuestionPoints::make($q, $embedded['embeddings'][$i], $embedded['model'])
                )->all();

                $qdrant->upsert(QdrantClient::COLLECTION_QUESTIONS, $points);
                $indexed += count($points);
                $bar->advance(count($points));
            });

        $bar->finish();
        $this->newLine();

        return $indexed;
    }
}
