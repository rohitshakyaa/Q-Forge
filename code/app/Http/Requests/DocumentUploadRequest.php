<?php

namespace App\Http\Requests;

use App\Models\DocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is already behind auth:sanctum + role:admin.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50 MB, per the upload screen.
            'type' => ['required', Rule::in([DocumentUpload::TYPE_SYLLABUS, DocumentUpload::TYPE_PAST_PAPER])],

            // A past paper's questions need a subject to belong to; a syllabus may
            // be filed before its subject exists.
            'subject_id' => [
                Rule::requiredIf(fn () => $this->input('type') === DocumentUpload::TYPE_PAST_PAPER),
                'nullable',
                'integer',
                'exists:subjects,id',
            ],
            'exam_year' => ['nullable', 'string', 'max:20'],
        ];
    }
}
