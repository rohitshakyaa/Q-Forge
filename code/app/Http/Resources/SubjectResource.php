<?php

namespace App\Http\Resources;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subject
 */
class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'syllabus' => $this->syllabus,
            // No teacher↔subject assignment exists in M1; surfaced as 0 for the UI.
            'teachers' => 0,
            'units_count' => $this->whenCounted('units'),
            'questions_count' => $this->whenCounted('questions'),
            'units' => UnitResource::collection($this->whenLoaded('units')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
