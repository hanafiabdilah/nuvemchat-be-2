<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\VirtualNumber;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A rented number changed state: activated, cancelled, renewed, failed.
 *
 * Metadata only — the codes travel on VirtualNumberSmsReceived. This one exists
 * so a list open in another tab stops showing a number as active seconds after
 * somebody cancelled it.
 */
class VirtualNumberUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public VirtualNumber $number,
    ) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [Channels::tenant($this->number->tenant_id)];
    }

    public function broadcastAs(): string
    {
        return 'virtual-number-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->number->id,
            'status' => $this->number->status->value,
            'msisdn' => $this->number->msisdn,
            'renews_at' => $this->number->renews_at?->toISOString(),
        ];
    }
}
