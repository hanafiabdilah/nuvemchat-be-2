<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * This person's account was just deactivated (AgentDeactivation).
 *
 * Sent on their own channel so a dashboard that is open right now leaves at
 * once and says why, instead of looking alive until the next click fails. The
 * tokens are already gone, so the event only carries the news.
 *
 * `ShouldBroadcastNow`, not the queue: the same reason as SessionSuperseded.
 */
class AccountDeactivated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $userId) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [Channels::user($this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'account-deactivated';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
