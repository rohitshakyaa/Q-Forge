<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuestionController extends Controller
{
    /**
     * Paginated, filterable question bank.
     * Filters: subject (code), unit (id), type, difficulty, status, upload (id).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $questions = Question::query()
            ->with(['subject', 'unit'])
            ->when($request->filled('subject'), function ($q) use ($request) {
                $q->whereHas('subject', fn ($s) => $s->where('code', $request->input('subject')));
            })
            // Scopes the review queue to the candidates a single upload produced.
            ->when($request->filled('upload'), fn ($q) => $q->where('attributes->upload_id', (int) $request->input('upload')))
            ->when($request->filled('unit'), fn ($q) => $q->where('unit_id', $request->input('unit')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->input('difficulty')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('text', 'like', '%'.$request->input('search').'%'))
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20))
            ->withQueryString();

        return QuestionResource::collection($questions);
    }

    public function show(Question $question): QuestionResource
    {
        $question->load(['subject', 'unit']);

        return new QuestionResource($question);
    }

    public function store(QuestionRequest $request): QuestionResource
    {
        $question = Question::create($request->validated());
        $question->refresh()->load(['subject', 'unit']);

        return new QuestionResource($question);
    }

    public function update(QuestionRequest $request, Question $question): QuestionResource
    {
        $question->update($request->validated());
        $question->load(['subject', 'unit']);

        return new QuestionResource($question);
    }

    public function destroy(Question $question): \Illuminate\Http\Response
    {
        $question->delete();

        return response()->noContent();
    }
}
