<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The prepaid credit balance moved.
 *
 * Two readers, and they want it for opposite reasons: the top-up modal closes
 * when the Pix settles (same job `subscription-updated` does for a plan), and
 * the balance shown next to the AI agents falls in real time while a busy
 * afternoon spends it — a number that only refreshes on reload is the number a
 * workspace discovers was zero an hour ago.
 *
 * Carries the balance rather than a model: this rides the shared tenant
 * channel, so everything on it is visible to every member of the workspace, and
 * a balance is the whole of what they need.
 */
class CreditUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $balanceCents,
        public string $reason,
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
        return 'credit-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'balance_cents' => $this->balanceCents,
            'reason' => $this->reason,
        ];
    }
}
