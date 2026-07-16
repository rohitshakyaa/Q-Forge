<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrievable piece of course material (M6 Phase 2 — docs/RAG-GUIDE.md §5).
 *
 * `unit_id` set → a chunk of that unit's `content`; null → a subject-level
 * chunk of `subjects.syllabus`. Derived data, wholesale-replaced on re-chunk
 * (no factory, no user-facing API) — the vector twin lives in Qdrant under
 * this row's id.
 */
class ContentChunk extends Model
{
    protected $fillable = [
        'subject_id',
        'unit_id',
        'position',
        'heading',
        'text',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The text as embedded: context path + body (guide §5 — chunks carry their context). */
    public function embeddingText(): string
    {
        return $this->heading !== null && $this->heading !== ''
            ? "{$this->heading}\n{$this->text}"
            : (string) $this->text;
    }
}
