<?php

namespace App\Http\Resources;

use App\Models\DocumentUpload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentUpload
 */
class DocumentUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'status' => $this->status,
            'progress' => $this->progress(),
            'type' => $this->type,
            'subject_id' => $this->subject_id,
            'subject_code' => $this->whenLoaded('subject', fn () => $this->subject?->code),
            'original_filename' => $this->original_filename,
            'error' => $this->error,
            'exam_year' => $meta['exam_year'] ?? null,
            'pages' => $meta['pages'] ?? null,
            'ocr_pages' => $meta['ocr_pages'] ?? null,
            'questions_created' => $meta['questions_created'] ?? null,
            'questions_skipped' => $meta['questions_skipped'] ?? null,
            'questions_duplicate' => $meta['questions_duplicate'] ?? null,
            'questions_unlinked' => $meta['questions_unlinked'] ?? null,
            // Syllabus uploads only. The proposal carries every unit's markdown body,
            // so keep it off the index and send it only when a single upload is asked for.
            'courses' => $this->when(
                $request->routeIs('uploads.show'),
                fn () => $meta['courses'] ?? [],
            ),
            'imported_subject_id' => $meta['imported_subject_id'] ?? null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
