<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubjectController extends Controller
{
    /**
     * Shared read (admin + teacher): list of subjects with counts.
     */
    public function index(): AnonymousResourceCollection
    {
        $subjects = Subject::query()
            ->withCount(['units', 'questions'])
            ->with('units') // unit names power the catalog cards (no questions embedded here)
            ->orderBy('code')
            ->get();

        return SubjectResource::collection($subjects);
    }

    /**
     * Shared read (admin + teacher): a subject with its units and their questions.
     */
    public function show(Subject $subject): SubjectResource
    {
        $subject->load(['units.questions' => fn ($q) => $q->orderBy('id')]);

        return new SubjectResource($subject);
    }

    public function store(SubjectRequest $request): SubjectResource
    {
        $subject = Subject::create($request->validated());

        return new SubjectResource($subject);
    }

    public function update(SubjectRequest $request, Subject $subject): SubjectResource
    {
        $subject->update($request->validated());

        return new SubjectResource($subject);
    }

    public function destroy(Subject $subject): \Illuminate\Http\Response
    {
        $subject->delete();

        return response()->noContent();
    }
}
