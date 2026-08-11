<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
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
 */
class GroupRemoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int|string>  $conversationIds
     */
    public function __construct(
        public int $tenantId,
        public int $contactId,
        public array $conversationIds,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('tenant-channel.' . $this->tenantId),
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
