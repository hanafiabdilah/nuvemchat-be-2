<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Max body bytes to put on the wire. Reverb rejects frames above
     * max_message_size (default 10KB) with "Payload too large" — a full email
     * body blew every broadcast, so no message ever arrived in realtime. The
     * SPA sees body_truncated and pulls the full row via the messages delta.
     */
    public const BROADCAST_BODY_LIMIT = 4000;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Message $message,
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
            Channels::connection(
                $this->message->conversation->connection->tenant_id,
                $this->message->conversation->connection->id,
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message-received';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        $data = (new MessageResource($this->message))->resolve();

        if (is_string($data['body'] ?? null) && strlen($data['body']) > self::BROADCAST_BODY_LIMIT) {
            // mb_strcut never splits a UTF-8 character at the byte boundary.
            $data['body'] = mb_strcut($data['body'], 0, self::BROADCAST_BODY_LIMIT);
            $data['body_truncated'] = true;
        }

        return $data;
    }
}
