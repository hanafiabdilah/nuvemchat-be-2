<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Http\Resources\CallResource;
use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "Your phone is ringing" — addressed to each agent being rung, on their own
 * channel, because who is rung is decided here (permission, presence, a call
 * already in progress) and the connection channel cannot say it to some of its
 * subscribers and not others.
 */
class IncomingCall implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<int, int>  $userIds */
    public function __construct(public Call $call, public array $userIds)
    {
        //
    }

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => Channels::user($id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'call-incoming';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $call = $this->call->loadMissing('connection');

        return [
            'call' => array_merge((new CallResource($call))->toArray(), ['ringing_me' => true]),
            'connection' => [
                'id' => $call->connection_id,
                'name' => $call->connection?->name,
            ],
        ];
    }
}
