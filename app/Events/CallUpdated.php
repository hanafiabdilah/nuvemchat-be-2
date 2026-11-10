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
 * A call moved: it started ringing, somebody picked it up, it connected, it
 * ended. Told to everyone on the connection, which is what lets the other
 * agents see that Ana is on the phone with this customer — and what makes
 * every other phone stop ringing the moment she answers.
 *
 * Broadcast now, never queued: "stop ringing" that waits behind a worker
 * arrives after somebody else has also pressed Answer.
 */
class CallUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Call $call)
    {
        //
    }

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [Channels::connection($this->call->tenant_id, $this->call->connection_id)];
    }

    public function broadcastAs(): string
    {
        return 'call-updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['call' => (new CallResource($this->call))->toArray()];
    }
}
