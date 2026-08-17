<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an agent takes a conversation over from whoever was holding it —
 * transfer's mirror image, and the reason it needs its own event: the person
 * who has to hear about it is the one who *lost* the thread, not the one who
 * gained it. Broadcast on the connection channel so only agents who hold that
 * inbox see it; the state sync itself rides on ConversationUpdated.
 */
class ConversationTakenOver implements ShouldBroadcast
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
            Channels::connection(
                $this->conversation->connection->tenant_id,
                $this->conversation->connection->id,
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation-taken-over';
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
