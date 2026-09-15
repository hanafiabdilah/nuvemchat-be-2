<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery in an endpoint's log: what was sent and what came back. The
 * payload is the workspace's own data, sent to its own URL, so it is shown in
 * full — that is what makes the log useful for debugging a receiver.
 */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event' => $this->event,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'error' => $this->error,
            'payload' => json_decode((string) $this->payload, true),
            'created_at' => $this->created_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
