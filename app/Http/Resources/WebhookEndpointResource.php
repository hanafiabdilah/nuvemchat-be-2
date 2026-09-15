<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A webhook endpoint as the dashboard lists it — never the secret itself. */
class WebhookEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events ?? [],
            'is_active' => (bool) $this->is_active,
            'secret_hint' => $this->secretHint(),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_delivery_at' => $this->last_delivery_at?->toIso8601String(),
            'last_response_status' => $this->last_response_status,
        ];
    }
}
