<?php

namespace App\Services\PaperGeneration;

use App\Models\Question;
use App\Services\PaperGeneration\Support\CompiledBlueprint;
use App\Services\PaperGeneration\Support\Slot;
use Illuminate\Support\Collection;

/**
 * Builds the candidate pool for a slot: approved questions matching the slot's
 * subject/type/marks, restricted to the blueprint's allowed units, excluding
 * questions already chosen in the current paper.
 *
 * The cross-paper "exclude last N papers" rule (M3) is injected as an optional
 * query closure so it plugs in here without changing call sites. In M2 it is a
 * no-op (defaults to null).
 */
class CandidateFilter
{
    /**
     * @param  int[]  $excludedQuestionIds  ids already chosen in the current paper
     * @param  (callable(\Illuminate\Database\Eloquent\Builder): void)|null  $lastNExclusion  M3 cross-paper rule
     * @return Collection<int, Question>
     */
    public function for(
        Slot $slot,
        CompiledBlueprint $blueprint,
        array $excludedQuestionIds = [],
        ?callable $lastNExclusion = null,
    ): Collection {
        $query = Question::query()
            ->where('subject_id', $blueprint->subjectId)
            ->where('status', 'approved')
            ->where('type', $slot->type)
            ->where('marks', $slot->marks);

        if (! empty($blueprint->allowedUnitIds)) {
            $query->whereIn('unit_id', $blueprint->allowedUnitIds);
        }

        if (! empty($excludedQuestionIds)) {
            $query->whereNotIn('id', $excludedQuestionIds);
        }

        if ($lastNExclusion !== null) {
            $lastNExclusion($query);
        }

        // Deterministic baseline ordering: least-recently-used first, then id.
        return $query->orderBy('used_count')->orderBy('id')->get();
    }
}
