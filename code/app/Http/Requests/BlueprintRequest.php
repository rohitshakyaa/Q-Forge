<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BlueprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Owner scoping must run before validation so non-owners get 403, not 422.
        $blueprint = $this->route('blueprint');

        return $blueprint === null || $blueprint->owner_id === $this->user()?->id;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // Client sends the subject `code`; controller resolves it to subject_id.
            'subject' => [$required, 'string', 'exists:subjects,code'],
            'name' => [$required, 'string', 'max:255'],
            'total_marks' => [$required, 'integer', 'min:0'],
            'duration' => [$required, 'integer', 'min:0'],
            'ai_assist' => ['sometimes', 'boolean'],

            // Strict columns, light JSON: definition must carry the four expected keys.
            'definition' => [$required, 'array'],
            'definition.sections' => [$required, 'array'],
            'definition.unitRules' => [$required, 'array'],
            'definition.unitAllocations' => [$required, 'array'],
            'definition.exclusionRules' => [$required, 'array'],
        ];
    }
}
