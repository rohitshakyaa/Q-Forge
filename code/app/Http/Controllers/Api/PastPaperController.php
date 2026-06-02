<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PastPaperRequest;
use App\Models\Subject;
use App\Services\ImportedPaperService;
use App\Services\PaperGeneration\Support\PaperViewModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PastPaperController extends Controller
{
    public function __construct(private readonly ImportedPaperService $service)
    {
    }

    /**
     * Record a real, historical exam for a subject as an imported paper (M3.1).
     * Admin-only (route middleware). Lands in the subject-wide repetition window
     * so freshly generated papers avoid questions that were on real exams.
     */
    public function store(PastPaperRequest $request, Subject $subject): JsonResponse
    {
        $validated = $request->validated();

        $paper = $this->service->record(
            $subject,
            $validated['name'],
            Carbon::parse($validated['exam_date']),
            $validated['question_ids'] ?? [],
            $validated['new_questions'] ?? [],
            $request->user(),
        );

        return response()->json(['paper' => PaperViewModel::for($paper)->toArray()], 201);
    }
}
