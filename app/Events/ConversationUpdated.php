<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Conversation $conversation
    )
    {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
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
        return 'conversation-updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        $data = (new ConversationResource($this->conversation))->resolve();

        // last_message here only feeds the conversation-list preview (one
        // line), but a full email body pushes the frame over Reverb's
        // max_message_size and the whole broadcast is dropped ("Payload too
        // large") — truncate hard. The thread itself reads from the messages
        // store, not from this payload.
        $lastMessage = $data['last_message'] ?? null;
        if ($lastMessage instanceof \App\Http\Resources\MessageResource) {
            $lastMessage = $lastMessage->resolve();
            $data['last_message'] = $lastMessage;
        }
        if (is_array($lastMessage) && is_string($lastMessage['body'] ?? null) && strlen($lastMessage['body']) > 500) {
            $data['last_message']['body'] = mb_strcut($lastMessage['body'], 0, 500);
            $data['last_message']['body_truncated'] = true;
        }

        return $data;
    }
}
