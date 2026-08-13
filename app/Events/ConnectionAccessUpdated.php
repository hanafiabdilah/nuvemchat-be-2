<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An agent's connection assignments changed.
 *
 * Sent on the agent's own channel because the change is about them, not about
 * any one connection — and in the revoke case they are about to lose the very
 * connection channels this could otherwise have been sent on.
 *
 * Without it, a revoke only takes hold at the agent's next login: their tab
 * stays subscribed to the connection channel it was authorized for, and its
 * IndexedDB copy of the history stays on disk. With it, the client refetches
 * its connections, drops the channels it no longer holds, and purges the local
 * cache within seconds.
 */
class ConnectionAccessUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->user->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'connection-access-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'connection_ids' => array_map(
                'strval',
                $this->user->connections()->pluck('connections.id')->all(),
            ),
        ];
    }
}
