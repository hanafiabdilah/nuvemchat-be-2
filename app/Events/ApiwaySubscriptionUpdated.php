<?php

namespace App\Events;

use App\Models\ApiwaySubscription;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired on any API Way subscription state change (paid, provisioned, renewed,
 * expired, cancelled) so the SPA can refresh the instance list and auto-close
 * the Pix checkout modal.
 */
class ApiwaySubscriptionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ApiwaySubscription $subscription
    ) {
        //
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('tenant-channel.' . $this->subscription->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'apiway-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->subscription->id,
            'status' => $this->subscription->status->value,
            'source' => $this->subscription->source->value,
            'expires_at' => $this->subscription->expires_at?->toISOString(),
        ];
    }
}
