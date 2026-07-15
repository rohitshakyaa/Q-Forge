<?php

namespace App\Models;

use Database\Factories\DocumentUploadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentUpload extends Model
{
    /** @use HasFactory<DocumentUploadFactory> */
    use HasFactory;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PARSED = 'parsed';

    public const STATUS_FAILED = 'failed';

    public const TYPE_SYLLABUS = 'syllabus';

    public const TYPE_PAST_PAPER = 'past_paper';

    /** Rough completion percentage per status, for the frontend's polling UI. */
    private const PROGRESS = [
        self::STATUS_UPLOADED => 10,
        self::STATUS_PROCESSING => 55,
        self::STATUS_PARSED => 100,
        self::STATUS_FAILED => 100,
    ];

    protected $fillable = [
        'uploader_id',
        'subject_id',
        'type',
        'original_filename',
        'stored_path',
        'status',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Absolute path to this document *as the Python container sees it*.
     */
    public function pythonPath(): string
    {
        return rtrim((string) config('services.python.shared_root'), '/').'/'.ltrim($this->stored_path, '/');
    }

    public function progress(): int
    {
        return self::PROGRESS[$this->status] ?? 0;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_PARSED, self::STATUS_FAILED], true);
    }
}
