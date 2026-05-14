<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiHubSkillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ai_hub_agent_id' => $this->ai_hub_agent_id,
            'hub_skill_id' => $this->hub_skill_id,
            'name' => $this->name,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
