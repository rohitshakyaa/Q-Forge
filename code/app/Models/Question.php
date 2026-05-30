<?php

namespace App\Models;

use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'unit_id',
        'type',
        'marks',
        'difficulty',
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
