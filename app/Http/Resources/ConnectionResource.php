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
            'name' => $this->name,
            'color' => $this->color,
            'status' => $this->status,
            'credentials' => $this->credentials,
            'automated_messages' => [
                'accept_message' => $this->accept_message,
                'closing_message' => $this->closing_message,
            ],
            'flow' => new FlowResource($this->flow),
            'api_key' => $this->api_key,
            // 'webhook_url' => route('webhook.chat', $this->id),
            'created_at' => $this->created_at,
        ];
    }
}
