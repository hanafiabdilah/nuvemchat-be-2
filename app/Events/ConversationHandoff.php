<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the AI stops and a conversation needs a human agent. Broadcast to
 * every agent on the tenant channel so the multi-agent inbox can raise a
 * realtime notification (toast/sound) and surface it in the "needs human" queue.
 */
class ConversationHandoff implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public ?string $reason = null,
    ) {
        //
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('tenant-channel.' . $this->conversation->connection->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation-handoff';
    }

    /**
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'conversation' => (new ConversationResource($this->conversation))->resolve(),
        ];
    }
}
