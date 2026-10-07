<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The account was just signed in to somewhere else, and its other sessions
 * were ended.
 *
 * Sent on the person's own channel, which every one of their devices is
 * subscribed to — including, a moment later, the one that just signed in, and
 * a Back Office operator's impersonation tab, which is not ended at all. So
 * the payload names the sessions that were ENDED (`ended`, the id half of each
 * token) and a dashboard acts only when its own id is on the list. Nothing
 * secret travels: the id is not the token.
 *
 * `ShouldBroadcastNow`, not the queue: the old device has already lost its
 * token, and until this arrives it is a dashboard that looks alive and fails
 * on the next click.
 */
class SessionSuperseded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        /** @var array<int, int> */
        public array $ended,
        public ?string $device,
        public int $at,
    ) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [Channels::user($this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'session-superseded';
    }

    public function broadcastWith(): array
    {
        return [
            'ended' => array_map('strval', $this->ended),
            'device' => $this->device,
            'at' => $this->at,
        ];
    }
}
