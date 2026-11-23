<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An incident started, changed, ran out or was resolved — or more customers
 * were told about it.
 *
 * Carries ids only. It rides the shared tenant channel, so everything in it
 * reaches every member of the workspace, including attendants the incident's
 * connections are not theirs; the banner re-reads GET /incidents/active, which
 * answers each of them with the incidents on the inboxes they can see.
 */
class IncidentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $incidentId,
        public string $status,
    ) {
        //
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            Channels::tenant($this->tenantId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'incident-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'status' => $this->status,
        ];
    }
}
