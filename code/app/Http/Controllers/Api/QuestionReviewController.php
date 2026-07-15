<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The extraction review queue: admins promote `pending` candidates into the bank.
 *
 * Approval is the gate the generator trusts — only `approved` questions are ever
 * selected — so it refuses anything the parser left incomplete.
 */
class QuestionReviewController extends Controller
{
    /**
     * Approve one candidate, optionally correcting it on the way through.
     */
    public function approve(Request $request, Question $question): QuestionResource
    {
        $data = $request->validate([
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'marks' => ['nullable', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', Rule::in(['short', 'long', 'mcq'])],
            'difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard'])],
            'text' => ['nullable', 'string'],
        ]);

        $question->fill(array_filter($data, fn ($value) => $value !== null));

        $this->assertUnitBelongsToSubject($question);
        $this->assertReadyForApproval($question);

        $question->status = 'approved';
        $question->save();

        return new QuestionResource($question->load(['subject', 'unit']));
    }

    public function reject(Question $question): QuestionResource
    {
        $question->update(['status' => 'rejected']);

        return new QuestionResource($question->load(['subject', 'unit']));
    }

    /**
     * Approve every candidate that is already complete, and say which were not.
     *
     * Partial success beats all-or-nothing here: a reviewer clearing a queue of
     * forty should not be blocked by the two the parser could not tag.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $questions = $this->resolveBulk($request);

        $approved = [];
        $skipped = [];

        foreach ($questions as $question) {
            if ($question->unit_id === null || $question->marks === null) {
                $skipped[] = [
                    'id' => $question->id,
                    'reason' => $question->unit_id === null ? 'missing unit' : 'missing marks',
                ];

                continue;
            }

            $question->update(['status' => 'approved']);
            $approved[] = $question->id;
        }

        return response()->json(['approved' => $approved, 'skipped' => $skipped]);
    }

    public function bulkReject(Request $request): JsonResponse
    {
        $ids = $this->resolveBulk($request)->pluck('id');

        Question::whereIn('id', $ids)->update(['status' => 'rejected']);

        return response()->json(['rejected' => $ids->values()]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Question>
     */
    private function resolveBulk(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:questions,id'],
        ]);

        return Question::whereIn('id', $data['ids'])->get();
    }

    private function assertUnitBelongsToSubject(Question $question): void
    {
        if ($question->unit_id === null) {
            return;
        }

        $belongs = Unit::where('id', $question->unit_id)
            ->where('subject_id', $question->subject_id)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'unit_id' => 'The unit must belong to the question\'s subject.',
            ]);
        }
    }

    /**
     * The parser leaves `unit_id` and `marks` null when a paper does not print
     * them. The generator cannot place such a question, so approval must fill them.
     */
    private function assertReadyForApproval(Question $question): void
    {
        $missing = [];

        if ($question->unit_id === null) {
            $missing['unit_id'] = 'A unit is required before this question can be approved.';
        }

        if ($question->marks === null) {
            $missing['marks'] = 'Marks are required before this question can be approved.';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }
}
