<?php

namespace App\Http\Requests;

use App\Services\Extraction\SyllabusImporter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SyllabusImportRequest extends FormRequest
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
            'subject.code' => ['required', 'string', 'max:32'],
            'subject.name' => ['required', 'string', 'max:255'],
            'subject.description' => ['nullable', 'string'],
            'subject.syllabus' => ['nullable', 'string'],

            'units' => ['required', 'array', 'min:1'],
            // Required, not nullable: the parser leaves a name null when the syllabus
            // printed none, and such a unit must be named before it enters the bank.
            'units.*.name' => ['required', 'string', 'max:255'],
            'units.*.number' => ['nullable', 'integer', 'min:1'],
            'units.*.hours' => ['nullable', 'integer', 'min:1', 'max:255'],
            'units.*.content' => ['nullable', 'string'],

            'update_existing' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Two units that differ only in case or punctuation would import as duplicates,
     * because the importer matches on a normalized name.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $importer = new SyllabusImporter;
            $seen = [];

            foreach ((array) $this->input('units', []) as $index => $unit) {
                $name = $unit['name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    continue; // Already reported by the `required` rule.
                }

                $fingerprint = $importer->fingerprint($name);
                if (isset($seen[$fingerprint])) {
                    $v->errors()->add(
                        "units.{$index}.name",
                        'This unit duplicates another unit in the list.',
                    );

                    continue;
                }

                $seen[$fingerprint] = true;
            }
        });
    }
}
