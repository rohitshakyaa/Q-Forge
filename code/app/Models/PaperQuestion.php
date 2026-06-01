<?php

namespace App\Models;

use Database\Factories\PaperQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperQuestion extends Model
{
    /** @use HasFactory<PaperQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'paper_id',
        'question_id',
        'unit_id',
        'section_label',
        'display_no',
        'marks',
        'is_ai',
    ];

    protected function casts(): array
    {
        return [
            'display_no' => 'integer',
            'marks' => 'integer',
            'is_ai' => 'boolean',
        ];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
