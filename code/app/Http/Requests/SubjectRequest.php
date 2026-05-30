<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gating handled by route middleware
    }

    public function rules(): array
    {
        $subject = $this->route('subject');
        $codeRule = Rule::unique('subjects', 'code');
        if ($subject) {
            $codeRule->ignore($subject->id);
        }

        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:32', $codeRule],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'syllabus' => ['nullable', 'string'],
        ];
    }
}
