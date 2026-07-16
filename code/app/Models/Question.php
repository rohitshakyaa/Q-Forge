<?php

namespace App\Models;

use App\Observers\QuestionObserver;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// M6: every save/delete queues a Qdrant vector sync (no-op when RAG is disabled).
#[ObservedBy(QuestionObserver::class)]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'unit_id',
        'type',
        'marks',
        'text',
        'source',
        'status',
        'attributes',
        'used_count',
    ];

    protected function casts(): array
    {
        return [
            'marks' => 'integer',
            'used_count' => 'integer',
            'attributes' => 'array',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * The *primary* unit — the one the question is listed and allocated under.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Every unit the question touches, primary included (`question_unit` pivot).
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class);
    }

    /**
     * Every unit id the question is tagged with, for coverage/filter checks.
     * Falls back to the primary unit if the pivot is somehow empty, so legacy
     * rows and hand-rolled fixtures degrade to single-unit behaviour.
     *
     * @return int[]
     */
    public function taggedUnitIds(): array
    {
        $ids = $this->units->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === [] && $this->unit_id !== null) {
            $ids = [(int) $this->unit_id];
        }

        return $ids;
    }

    /**
     * Keep the pivot consistent with `unit_id`.
     *
     * With an explicit set, replace the links wholesale (callers validate that
     * the set contains the primary). With none, just make sure the current
     * primary is linked — used when the primary changes without the caller
     * expressing an opinion about the extra tags, which are then kept.
     *
     * @param  int[]|null  $unitIds
     */
    public function syncUnitLinks(?array $unitIds = null): void
    {
        if ($unitIds !== null) {
            $this->units()->sync($unitIds);
        } elseif ($this->unit_id !== null) {
            $this->units()->syncWithoutDetaching([$this->unit_id]);
        }
    }
}
