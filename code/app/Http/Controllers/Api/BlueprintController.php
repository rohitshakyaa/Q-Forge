<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlueprintRequest;
use App\Http\Resources\BlueprintResource;
use App\Models\Blueprint;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlueprintController extends Controller
{
    /**
     * Teacher's own blueprints only.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $blueprints = Blueprint::query()
            ->with('subject')
            ->where('owner_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return BlueprintResource::collection($blueprints);
    }

    public function show(Request $request, Blueprint $blueprint): BlueprintResource
    {
        $this->authorizeOwner($request, $blueprint);
        $blueprint->load('subject');

        return new BlueprintResource($blueprint);
    }

    public function store(BlueprintRequest $request): BlueprintResource
    {
        $blueprint = Blueprint::create($this->mapped($request) + [
            'owner_id' => $request->user()->id,
        ]);
        $blueprint->load('subject');

        return new BlueprintResource($blueprint);
    }

    public function update(BlueprintRequest $request, Blueprint $blueprint): BlueprintResource
    {
        $this->authorizeOwner($request, $blueprint);
        $blueprint->update($this->mapped($request));
        $blueprint->load('subject');

        return new BlueprintResource($blueprint);
    }

    public function destroy(Request $request, Blueprint $blueprint): \Illuminate\Http\Response
    {
        $this->authorizeOwner($request, $blueprint);
        $blueprint->delete();

        return response()->noContent();
    }

    /**
     * Translate validated payload to columns; resolve subject `code` -> subject_id.
     */
    private function mapped(BlueprintRequest $request): array
    {
        $data = $request->validated();

        if (isset($data['subject'])) {
            $data['subject_id'] = Subject::where('code', $data['subject'])->value('id');
            unset($data['subject']);
        }

        return $data;
    }

    private function authorizeOwner(Request $request, Blueprint $blueprint): void
    {
        abort_unless($blueprint->owner_id === $request->user()->id, 403, 'This blueprint belongs to another user.');
    }
}
