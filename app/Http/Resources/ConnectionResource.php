<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConnectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'provider' => $this->provider,
            'name' => $this->name,
            'color' => $this->color,
            'status' => $this->status,
            'credentials' => $this->credentials,
            'created_at' => $this->created_at,
        ];
    }
}
