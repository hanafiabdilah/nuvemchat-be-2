<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BroadcastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sent = (int) $this->sent_count;
        $failed = (int) $this->failed_count;
        $skipped = (int) $this->skipped_count;
        $total = (int) $this->total_recipients;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'content_type' => $this->content_type->value,
            'payload' => $this->payload,
            'connection_id' => $this->connection_id,
            'connection' => ConnectionResource::make($this->whenLoaded('connection')),
            'created_by' => UserResource::make($this->whenLoaded('creator')),
            'tag' => TagResource::make($this->whenLoaded('tag')),
            'rate_per_minute' => (int) $this->rate_per_minute,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error' => $this->error,
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            // Same arithmetic the progress event uses, so a page that mixes a
            // polled response with a pushed one never shows two different
            // numbers for the same moment.
            'pending' => max(0, $total - $sent - $failed - $skipped),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
