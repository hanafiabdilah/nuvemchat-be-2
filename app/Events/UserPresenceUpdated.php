<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agent flipped their own Online/Offline switch.
 *
 * Sent on the person's own channel so every tab they have open shows the same
 * status: the switch is one value on the account, and a second tab still
 * saying "Online" after the first one said "Offline" reads as the click not
 * having worked. The heartbeat cannot carry it — it deliberately never touches
 * the switch.
 *
 * `ShouldBroadcastNow`: it is a few bytes, and a status that changes a minute
 * later in the other tab is the same confusion it exists to prevent.
 */
class UserPresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public string $presence,
    ) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [Channels::user($this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'presence-updated';
    }

    public function broadcastWith(): array
    {
        return ['presence' => $this->presence];
    }
}
