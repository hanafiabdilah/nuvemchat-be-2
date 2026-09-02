<?php

namespace App\Http\Resources;

use App\Services\AiAgentHub\AiAgentHubConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiHubTenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hub_tenant_id' => $this->hub_tenant_id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'status' => $this->status,
            'metadata' => $this->metadata,
            // Whether the platform can talk to the hub at all. It was once a
            // per-workspace key row; auth is now the platform's single hub
            // tenant token, so every workspace answers this the same way.
            'has_active_api_key' => (bool) AiAgentHubConfig::tenantToken(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
