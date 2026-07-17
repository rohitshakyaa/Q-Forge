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
     * Filters: subject (code), unit (id), type, status, source, upload (id).
     * Sort: `sort=used` puts the most-used questions first; default is newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $questions = Question::query()
            ->with(['subject', 'unit', 'units'])
            ->when($request->filled('subject'), function ($q) use ($request) {
                $q->whereHas('subject', fn ($s) => $s->where('code', $request->input('subject')));
            })
            // Scopes the review queue to the candidates a single upload produced.
            ->when($request->filled('upload'), fn ($q) => $q->where('attributes->upload_id', (int) $request->input('upload')))
            // Any-tag match: a multi-unit question surfaces under every unit it touches.
            ->when($request->filled('unit'), fn ($q) => $q->whereHas('units', fn ($u) => $u->where('units.id', $request->input('unit'))))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('search'), fn ($q) => $q->where('text', 'like', '%'.$request->input('search').'%'))
            ->when($request->input('sort') === 'used', fn ($q) => $q->orderByDesc('used_count'))
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20))
            ->withQueryString();

        return QuestionResource::collection($questions);
    }

    public function show(Question $question): QuestionResource
    {
        $question->load(['subject', 'unit', 'units']);

        return new QuestionResource($question);
    }

    public function store(QuestionRequest $request): QuestionResource
    {
        $data = $request->validated();
        $unitIds = $data['unit_ids'] ?? null;
        unset($data['unit_ids']);

        $question = Question::create($data);
        $question->syncUnitLinks($unitIds);
        $question->refresh()->load(['subject', 'unit', 'units']);

        return new QuestionResource($question);
    }

    public function update(QuestionRequest $request, Question $question): QuestionResource
    {
        $data = $request->validated();
        $unitIds = $data['unit_ids'] ?? null;
        unset($data['unit_ids']);

        $question->update($data);
        $question->syncUnitLinks($unitIds);
        $question->load(['subject', 'unit', 'units']);

        return new QuestionResource($question);
    }

    public function destroy(Question $question): \Illuminate\Http\Response
    {
        $question->delete();

        return response()->noContent();
    }
}
