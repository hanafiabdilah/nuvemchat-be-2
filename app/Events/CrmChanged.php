<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lead, deal or task changed — so an open board or list can read itself
 * again. Ids only, on the tenant channel: what changed is fetched through the
 * same visibility rules as everything else, never carried here.
 */
class CrmChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  string  $entity  lead | deal | task */
    public function __construct(
        public int $tenantId,
        public string $entity,
        public int $id,
        public ?int $pipelineId = null,
        public ?int $customerId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [Channels::tenant($this->tenantId)];
    }

    public function broadcastAs(): string
    {
        return 'crm-changed';
    }

    public function broadcastWith(): array
    {
        return ['entity' => $this->entity, 'id' => $this->id, 'pipeline_id' => $this->pipelineId, 'customer_id' => $this->customerId];
    }
}
