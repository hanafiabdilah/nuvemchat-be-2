<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A group was removed from the inbox: its threads must disappear from every
 * open panel right away.
 *
 * The conversation rows are kept server-side (restoring brings the history
 * back), so clients cannot learn about this from the usual delta sync — which
 * only ever adds. Hence an explicit event carrying the ids to drop locally.
 *
 * Emitted once per connection, not once per group: a group contact can hold
 * threads on more than one connection, and an agent must only be told to drop
 * the ones they can actually see.
 */
class GroupRemoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int|string>  $conversationIds
     */
    public function __construct(
        public int $tenantId,
        public int $connectionId,
        public int $contactId,
        public array $conversationIds,
    ) {}

    public function broadcastOn(): array
    {
        return [
            Channels::connection($this->tenantId, $this->connectionId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'group-removed';
    }

    public function broadcastWith(): array
    {
        return [
            'contact_id' => $this->contactId,
            'conversation_ids' => array_map('strval', $this->conversationIds),
        ];
    }
}
