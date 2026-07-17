<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Services\Rag\SimilarQuestionFinder;
use Illuminate\Console\Command;

/**
 * Backfill: pre-tag pending extracted candidates from their stored RAG unit
 * suggestions (M6 Phase 3b).
 *
 * Auto-assignment normally happens at extraction time, but candidates imported
 * before the rule existed sit unassigned with `attributes.suggested_units`
 * already computed. This replays the same rule over them — no re-embedding,
 * no Qdrant round-trips, just the stored suggestions — so it is cheap and safe
 * to re-run (already-tagged candidates are never touched).
 */
class RagAutoAssignUnits extends Command
{
    protected $signature = 'qforge:rag:auto-assign-units
        {--dry-run : Report what would be assigned without writing anything}';

    protected $description = 'Assign units to pending unassigned candidates from their stored RAG suggestions';

    public function handle(SimilarQuestionFinder $finder): int
    {
        $candidates = Question::query()
            ->where('status', 'pending')
            ->where('source', 'extracted')
            ->whereNull('unit_id')
            ->whereNotNull('attributes->suggested_units')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to do — no pending unassigned candidates with stored suggestions.');

            return self::SUCCESS;
        }

        $threshold = (float) config('services.qdrant.unit_auto_assign_threshold');
        $assigned = 0;
        $skipped = 0;

        foreach ($candidates as $question) {
            $suggestions = $question->attributes['suggested_units'] ?? [];

            if ($this->option('dry-run')) {
                $top = $suggestions[0] ?? null;
                $would = $top !== null && (float) $top['score'] >= $threshold;
                $would ? $assigned++ : $skipped++;
                $this->line(sprintf(
                    '  #%d → %s',
                    $question->id,
                    $would ? "unit {$top['unit_id']} ({$top['score']})" : 'below threshold',
                ));

                continue;
            }

            $finder->autoAssignFromSuggestions($question, $suggestions) ? $assigned++ : $skipped++;
        }

        $verb = $this->option('dry-run') ? 'would assign' : 'assigned';
        $this->info("{$candidates->count()} candidate(s) checked: {$verb} {$assigned}, skipped {$skipped} (threshold {$threshold}).");

        return self::SUCCESS;
    }
}
