<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'name' => $this->name,
            'position' => $this->position,
            'hours' => $this->hours,
            // The markdown body runs to kilobytes; the catalog embeds units in every
            // subject, so keep it out of list payloads unless it was asked for.
            'content' => $this->when(
                $request->routeIs('units.show') || $request->boolean('with_content'),
                fn () => $this->content,
            ),
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
