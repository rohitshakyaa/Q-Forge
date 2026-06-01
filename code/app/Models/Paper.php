<?php

namespace App\Models;

use Database\Factories\PaperFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paper extends Model
{
    /** @use HasFactory<PaperFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'blueprint_id',
        'subject_id',
        'name',
        'total_marks',
        'duration',
        'status',
        'export_count',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_marks' => 'integer',
            'duration' => 'integer',
            'export_count' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function paperQuestions(): HasMany
    {
        return $this->hasMany(PaperQuestion::class)->orderBy('display_no');
    }
}
