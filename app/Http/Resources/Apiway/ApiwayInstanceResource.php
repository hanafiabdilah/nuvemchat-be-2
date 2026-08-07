<?php

namespace App\Http\Resources\Apiway;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiwayInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_instance_id' => $this->provider_instance_id,
            'name' => $this->name,
            'ip_address' => $this->ip_address,
            'status' => $this->status,
            'available' => $this->isAvailable(),
            'connection' => $this->whenLoaded('connection', fn () => $this->connection ? [
                'id' => $this->connection->id,
                'name' => $this->connection->name,
                'status' => $this->connection->status,
                'phone_number' => $this->connection->credentials['phone_number'] ?? null,
            ] : null),
            'subscription' => new ApiwaySubscriptionResource($this->whenLoaded('subscription')),
            'created_at' => $this->created_at,
        ];
    }
}
