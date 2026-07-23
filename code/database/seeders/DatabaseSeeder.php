<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            // QForgeDemoSeeder::class,
            TuPastPaperSeeder::class,
            DemoBlueprintSeeder::class,
        ]);

        $this->reindexRag();
    }

    /**
     * Rebuild the Qdrant vector index from the freshly seeded rows.
     *
     * Seeding runs with model events muted (WithoutModelEvents), so the embedding
     * observers (SyncQuestionEmbedding / SyncSubjectChunks) never fire — this one
     * pass backfills them for every seeded subject and approved question.
     *
     * `--fresh` drops and recreates the collections first: Qdrant is a separate
     * store, so `migrate:fresh` (which wipes MySQL and restarts ids at 1) would
     * otherwise leave stale points from a previous, larger dataset orphaned in the
     * index. A full drop-and-rebuild guarantees Qdrant exactly mirrors MySQL.
     *
     * Non-fatal by design: a demo box with Qdrant or the Python embedding service
     * down should still seed cleanly, so a failure only warns. Re-run manually with
     * `php artisan qforge:rag:reindex --fresh` once those services are up.
     */
    private function reindexRag(): void
    {
        try {
            $exit = Artisan::call('qforge:rag:reindex', ['--fresh' => true]);
            $output = trim(Artisan::output());

            if ($exit === 0) {
                $this->command?->info('RAG index rebuilt from seeded data.'.($output !== '' ? "\n".$output : ''));
            } else {
                $this->command?->warn(
                    'Skipped RAG reindex (exit '.$exit.') — run `php artisan qforge:rag:reindex` once Qdrant + Python are up.'
                    .($output !== '' ? "\n".$output : '')
                );
            }
        } catch (\Throwable $e) {
            $this->command?->warn(
                'Skipped RAG reindex: '.$e->getMessage()
                .' — run `php artisan qforge:rag:reindex` once Qdrant + Python are up.'
            );
        }
    }
}
