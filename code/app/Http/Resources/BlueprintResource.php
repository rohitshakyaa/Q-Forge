<?php

namespace App\Http\Resources;

use App\Models\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Blueprint
 */
class BlueprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'subject_id' => $this->subject_id,
            'subject_code' => $this->whenLoaded('subject', fn () => $this->subject->code),
            'name' => $this->name,
            'total_marks' => $this->total_marks,
            'duration' => $this->duration,
            'ai_assist' => $this->ai_assist,
            'definition' => $this->definition,
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
