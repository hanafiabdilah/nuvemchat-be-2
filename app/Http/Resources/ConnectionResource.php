<?php

namespace App\Http\Resources;

use App\Enums\Connection\Channel;
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
        $credentials = $this->credentials;

        if ($this->channel === Channel::Email && is_array($credentials)) {
            unset($credentials['password']);
        }

        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'name' => $this->name,
            'color' => $this->color,
            'status' => $this->status,
            'credentials' => $credentials,
            // Only email has an inbox to poll; other channels are push/webhook.
            'sync' => $this->when($this->channel === Channel::Email, fn () => [
                'status' => $this->sync_status,
                'error' => $this->sync_error,
                'remaining' => $this->sync_remaining,
                'started_at' => $this->sync_started_at,
                'last_synced_at' => $this->last_synced_at,
            ]),
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
