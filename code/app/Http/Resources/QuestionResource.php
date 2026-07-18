<?php

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Question
 */
class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'unit_id' => $this->unit_id,
            'subject_code' => $this->whenLoaded('subject', fn () => $this->subject->code),
            'subject_name' => $this->whenLoaded('subject', fn () => $this->subject->name),
            'unit_name' => $this->whenLoaded('unit', fn () => $this->unit->name),
            // Full multi-unit set (primary included) from the question_unit pivot.
            'unit_ids' => $this->whenLoaded('units', fn () => $this->units->pluck('id')->sort()->values()),
            'units' => $this->whenLoaded('units', fn () => $this->units
                ->sortBy('id')
                ->values()
                ->map(fn ($unit) => ['id' => $unit->id, 'name' => $unit->name])),
            'type' => $this->type,
            'marks' => $this->marks,
            'text' => $this->text,
            'source' => $this->source,
            'status' => $this->status,
            'attributes' => $this->attributes,
            'used_count' => $this->used_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
