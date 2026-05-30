<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitController extends Controller
{
    /**
     * Units for a subject (nested: GET /subjects/{subject}/units).
     */
    public function index(Subject $subject): AnonymousResourceCollection
    {
        return UnitResource::collection($subject->units()->withCount('questions')->get());
    }

    /**
     * Create a unit under a subject (POST /subjects/{subject}/units).
     */
    public function store(UnitRequest $request, Subject $subject): UnitResource
    {
        $data = $request->validated();
        $data['position'] ??= ($subject->units()->max('position') ?? 0) + 1;

        $unit = $subject->units()->create($data);

        return new UnitResource($unit);
    }

    public function show(Unit $unit): UnitResource
    {
        $unit->load(['questions' => fn ($q) => $q->orderBy('id')]);

        return new UnitResource($unit);
    }

    public function update(UnitRequest $request, Unit $unit): UnitResource
    {
        $unit->update($request->validated());

        return new UnitResource($unit);
    }

    public function destroy(Unit $unit): \Illuminate\Http\Response
    {
        $unit->delete();

        return response()->noContent();
    }
}
