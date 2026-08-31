<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An agent is writing in a thread — told to the *other* agents.
 *
 * The indicator that already existed points the other way: MessageService's
 * sendTyping() shows the customer that somebody is answering. Nobody on this
 * side of the glass ever heard it, which is how two agents end up writing the
 * same reply to the same thread.
 *
 * Separate from ConversationActivity on purpose. That one is a slot — a thread
 * is at one node, in one phase. This is a list: three agents can be typing at
 * once, and the row has to name all of them.
 *
 * Broadcast now, never queued: an eight-second signal that waits for a worker
 * is a signal about a moment that has passed.
 */
class AgentTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $connectionId,
        public int $tenantId,
        public int $userId,
        public string $userName,
        public bool $typing,
        public int $ttl,
    ) {
        //
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            Channels::connection($this->tenantId, $this->connectionId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent-typing';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'connection_id' => $this->connectionId,
            // The typist receives their own event too — the channel is shared
            // and there is no per-recipient filter on it. The client drops the
            // ones matching its own id, which also gets the two-tabs case
            // right: that is one person, not an audience.
            'user' => [
                'id' => $this->userId,
                'name' => $this->userName,
            ],
            'typing' => $this->typing,
            'ttl' => $this->ttl,
            'at' => now()->timestamp,
        ];
    }
}
