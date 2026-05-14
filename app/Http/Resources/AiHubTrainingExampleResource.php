<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiHubTrainingExampleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ai_hub_agent_id' => $this->ai_hub_agent_id,
            'hub_example_id' => $this->hub_example_id,
            'type' => $this->type,
            'input' => $this->input,
            'expected_output' => $this->expected_output,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
