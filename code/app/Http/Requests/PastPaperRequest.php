<?php

namespace App\Http\Requests;

use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the admin "record a past exam" payload (M3.1). Role gating is on the
 * route (role:admin); subject scoping is enforced here so an imported exam only
 * ever links questions belonging to its own subject.
 */
class PastPaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gating handled by route middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'exam_date' => ['required', 'date'],
            'question_ids' => ['array'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
            'new_questions' => ['array'],
            'new_questions.*.text' => ['required', 'string'],
            'new_questions.*.type' => ['required', Rule::in(['short', 'long', 'mcq'])],
            'new_questions.*.marks' => ['required', 'integer', 'min:1'],
            'new_questions.*.unit_id' => ['required', 'integer', 'exists:units,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            /** @var Subject $subject */
            $subject = $this->route('subject');

            $existingIds = collect($this->input('question_ids', []))->filter()->unique();
            $newQuestions = collect($this->input('new_questions', []));

            // A recorded exam must contain at least one question.
            if ($existingIds->isEmpty() && $newQuestions->isEmpty()) {
                $v->errors()->add('question_ids', 'Record at least one question (existing or new).');

                return;
            }

            // Every existing question must belong to this subject, else it would
            // corrupt another subject's exclusion set.
            if ($existingIds->isNotEmpty()) {
                $inSubject = Question::whereIn('id', $existingIds)
                    ->where('subject_id', $subject->id)
                    ->count();

                if ($inSubject !== $existingIds->count()) {
                    $v->errors()->add('question_ids', 'Every question must belong to this subject.');
                }
            }

            // Every inline question's unit must belong to this subject.
            $newQuestions->each(function ($payload, $i) use ($v, $subject) {
                $unitId = $payload['unit_id'] ?? null;
                if ($unitId
                    && ! Unit::where('id', $unitId)->where('subject_id', $subject->id)->exists()) {
                    $v->errors()->add("new_questions.$i.unit_id", 'The selected unit does not belong to this subject.');
                }
            });
        });
    }
}
