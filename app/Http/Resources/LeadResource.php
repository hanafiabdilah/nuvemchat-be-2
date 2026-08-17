<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card. Carries what the board draws plus what the drawer needs, because
 * opening a card must not cost a round trip — an agent triaging a column opens
 * a dozen in a row.
 */
class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $contact = $this->getRelationValue('contact');

        return [
            'id' => $this->id,
            'title' => $this->displayTitle(),
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'status' => $this->status->value,
            'source' => $this->source->value,

            'temperature' => $this->temperature->value,
            'temperature_score' => $this->temperature_score,

            'value' => $this->value !== null ? (float) $this->value : null,
            'currency' => $this->currency,

            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'owner_id' => $this->owner_id,

            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'channel' => $contact->channel,
                'photo_profile_url' => $contact->photo_profile_url,
            ] : null,

            // What the agent needs to remember the deal without opening the
            // thread: how long it has been quiet, and where it is stuck.
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'lost_reason' => $this->lost_reason,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Only loaded on the detail endpoint — the board would pay for a
            // join it never renders.
            'conversations' => $this->whenLoaded('conversations', fn () => $this->conversations->map(fn ($conversation) => [
                'id' => $conversation->id,
                'status' => $conversation->status->value,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ])->values()),

            'stage_events' => $this->whenLoaded('stageEvents', fn () => $this->stageEvents->map(fn ($event) => [
                'id' => $event->id,
                'to_stage_id' => $event->to_stage_id,
                'to_stage_name' => $event->to_stage_name,
                'user_name' => $event->getRelationValue('user')?->name,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
