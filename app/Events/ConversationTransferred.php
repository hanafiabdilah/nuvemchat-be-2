<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an agent transfers a conversation to another agent. Broadcast to
 * the tenant channel so the receiving agent gets a realtime notification
 * (toast/sound); the state sync itself rides on ConversationUpdated.
 */
class ConversationTransferred implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public User $fromAgent,
        public User $toAgent,
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
        return 'conversation-transferred';
    }

    /**
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'from_agent' => ['id' => $this->fromAgent->id, 'name' => $this->fromAgent->name],
            'to_agent' => ['id' => $this->toAgent->id, 'name' => $this->toAgent->name],
            'conversation' => (new ConversationResource($this->conversation))->resolve(),
        ];
    }
}
