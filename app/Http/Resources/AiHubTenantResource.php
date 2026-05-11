<?php

namespace App\Http\Resources;

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
            'has_active_api_key' => $this->whenLoaded('activeApiKey', fn () => (bool) $this->activeApiKey),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
