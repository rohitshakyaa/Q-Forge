<?php

namespace App\Models;

use App\Observers\ContentObserver;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// M6: content changes queue a chunk re-index (no-op when RAG is disabled).
#[ObservedBy(ContentObserver::class)]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'name',
        'position',
        'hours',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'hours' => 'integer',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
