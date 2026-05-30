<?php

namespace App\Models;

use Database\Factories\BlueprintFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blueprint extends Model
{
    /** @use HasFactory<BlueprintFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'subject_id',
        'name',
        'total_marks',
        'duration',
        'ai_assist',
        'definition',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'total_marks' => 'integer',
            'duration' => 'integer',
            'ai_assist' => 'boolean',
            'definition' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
