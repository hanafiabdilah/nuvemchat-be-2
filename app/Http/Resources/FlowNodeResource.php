<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowNodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'type' => $this->type->value,
            'data' => $this->data,
            'position_x' => $this->position_x,
            'position_y' => $this->position_y,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
