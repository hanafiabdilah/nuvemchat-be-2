<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiHubAgentProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ai_hub_agent_id' => $this->ai_hub_agent_id,
            'language' => $this->language,
            'tone' => $this->tone,
            'response_style' => $this->response_style,
            'instructions' => $this->instructions,
            'limits' => $this->limits,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
