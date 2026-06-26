<?php

namespace App\Events;

use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Subscription $subscription
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
        return 'subscription-updated';
    }

    public function broadcastWith(): array
    {
        return (new SubscriptionResource($this->subscription->loadMissing('plan')))->resolve();
    }
}
