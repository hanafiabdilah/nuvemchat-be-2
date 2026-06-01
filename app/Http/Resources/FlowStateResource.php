<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowStateResource extends JsonResource
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
            'conversation_id' => $this->conversation_id,
            'flow_id' => $this->flow_id,
            'current_node' => [
                'id' => $this->currentNode?->id,
                'type' => $this->currentNode?->type->value,
                'data' => $this->currentNode?->data,
            ],
            'state_data' => $this->state_data,
            'status' => $this->status->value,
            'completed_at' => $this->completed_at,
        ];
    }
}
