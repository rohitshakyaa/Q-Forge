<?php

namespace App\Models;

use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'syllabus',
    ];

    /**
     * Use the public `code` as the route key (e.g. CS302).
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('position');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
