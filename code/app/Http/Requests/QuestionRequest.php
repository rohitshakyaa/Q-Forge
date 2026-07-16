<?php

namespace App\Http\Requests;

use App\Models\Unit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gating handled by route middleware
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'subject_id' => [$required, 'integer', 'exists:subjects,id'],
            'unit_id' => [$required, 'integer', Rule::exists('units', 'id')],
            'unit_ids' => ['sometimes', 'array', 'min:1'],
            'unit_ids.*' => ['integer', Rule::exists('units', 'id')],
            'type' => [$required, 'string', Rule::in(['short', 'long', 'mcq'])],
            'marks' => [$required, 'integer', 'min:1'],
            'text' => [$required, 'string'],
            'source' => ['sometimes', Rule::in(['extracted', 'ai', 'manual'])],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])],
            'attributes' => ['nullable', 'array'],
            'used_count' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Ensure the chosen unit belongs to the chosen subject, and that `unit_ids`
     * (the full multi-unit set, when given) contains the primary unit and stays
     * within the subject.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $question = $this->route('question');

            // Effective values: PATCH may omit fields, falling back to the model.
            $subjectId = $this->input('subject_id', $question?->subject_id);
            $unitId = $this->input('unit_id', $question?->unit_id);

            if ($subjectId && $unitId
                && ! Unit::where('id', $unitId)->where('subject_id', $subjectId)->exists()) {
                $v->errors()->add('unit_id', 'The selected unit does not belong to the selected subject.');
            }

            $unitIds = $this->input('unit_ids');

            if (! is_array($unitIds) || $unitIds === []) {
                return;
            }

            if ($unitId && ! in_array((int) $unitId, array_map('intval', $unitIds), true)) {
                $v->errors()->add('unit_ids', 'The unit set must include the primary unit.');
            }

            if ($subjectId) {
                $within = Unit::whereIn('id', $unitIds)
                    ->where('subject_id', $subjectId)
                    ->count();

                if ($within !== count(array_unique($unitIds))) {
                    $v->errors()->add('unit_ids', 'Every unit must belong to the selected subject.');
                }
            }
        });
    }

    /**
     * Defaults for admin-created questions (decision: source=manual, status=approved).
     */
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST')) {
            $this->merge([
                'source' => $this->input('source', 'manual'),
                'status' => $this->input('status', 'approved'),
            ]);
        }
    }
}
