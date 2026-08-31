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
            // What the dashboard needs to tell the two apart: a rented row is
            // selectable like any other but cannot be edited, re-keyed or
            // deleted, and its usage is billed. `ai_token_pool_key_id` itself
            // never leaves the server — it names a platform asset the customer
            // has no business identifying.
            'is_rented' => $this->isRented(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
