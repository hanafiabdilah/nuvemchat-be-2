<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\Lead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A card moved, was created, or changed temperature band.
 *
 * Travels on the tenant channel rather than the per-connection one that carries
 * conversation content, and that is a deliberate, slightly uncomfortable
 * choice. A lead belongs to a *contact*, and contacts are already tenant-wide
 * in Pingly — ContactController@index filters on tenant_id alone, so an agent
 * with one connection can already list the whole workspace's contact book.
 * Putting leads on the tenant channel is therefore consistent with what the
 * product already does, not a new hole.
 *
 * What keeps it defensible is the payload: board fields only, never message
 * content. If per-connection scoping is wanted later, leads.source_connection_id
 * is already recorded to build it from.
 */
class LeadUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Lead $lead,
        /** True when the card left one column for another, so boards can animate. */
        public bool $moved = false,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            Channels::tenant($this->lead->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead-updated';
    }

    public function broadcastWith(): array
    {
        $lead = $this->lead->loadMissing(['contact', 'owner']);

        return [
            'id' => $lead->id,
            'stage_id' => $lead->stage_id,
            'pipeline_id' => $lead->pipeline_id,
            'status' => $lead->status->value,
            'temperature' => $lead->temperature->value,
            'value' => $lead->value !== null ? (float) $lead->value : null,
            'currency' => $lead->currency,
            'title' => $lead->displayTitle(),
            'owner_id' => $lead->owner_id,
            'owner_name' => $lead->getRelationValue('owner')?->name,
            'contact_id' => $lead->contact_id,
            'moved' => $this->moved,
        ];
    }
}
