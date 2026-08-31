<?php

namespace App\Http\Resources\TrainedAgent;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TrainedAgentHire */
class TrainedAgentHireResource extends JsonResource
{
    public function toArray($request): array
    {
        $snapshot = $this->blueprint_snapshot ?? [];

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,
            'blueprint_id' => $this->trained_agent_blueprint_id,
            // Falls back to the snapshot so a retired blueprint still has a
            // name in the tenant's list instead of an empty card.
            'blueprint_name' => $this->blueprint?->name ?? ($snapshot['name'] ?? null),
            'category' => $this->blueprint?->category?->name ?? ($snapshot['category'] ?? null),
            'icon' => $this->blueprint?->icon,
            'agent_name' => $this->agent_name,
            'ai_hub_agent_id' => $this->ai_hub_agent_id,
            // The tenant may have deleted the forked agent; the hire stays.
            'agent_exists' => $this->ai_hub_agent_id !== null && $this->relationLoaded('agent')
                ? $this->agent !== null
                : $this->ai_hub_agent_id !== null,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'failure_reason' => $this->meta['failure']['reason'] ?? null,
            'hired_at' => $this->hired_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
