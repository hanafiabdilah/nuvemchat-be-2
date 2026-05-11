<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiHubAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hub_agent_id' => $this->hub_agent_id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'description' => $this->description,
            'model' => $this->model,
            'system_prompt' => $this->system_prompt,
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
            'status' => $this->status,
            'handoff_rules' => $this->handoff_rules,
            'metadata' => $this->metadata,
            'provider_credential' => new AiHubProviderCredentialResource(
                $this->whenLoaded('providerCredential')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
