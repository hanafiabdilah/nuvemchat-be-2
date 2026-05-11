<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiHubProviderCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hub_provider_credential_id' => $this->hub_provider_credential_id,
            'provider' => $this->provider,
            'name' => $this->name,
            'key_preview' => $this->key_preview,
            'default_model' => $this->default_model,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
