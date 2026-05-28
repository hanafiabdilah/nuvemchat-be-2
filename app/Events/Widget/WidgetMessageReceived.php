<?php

namespace App\Events\Widget;

use App\Http\Resources\MessageResource;
use App\Models\LiveChatSession;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
    ) {
        //
    }

    public function broadcastOn(): array
    {
        $session = LiveChatSession::where('conversation_id', $this->message->conversation_id)->first();

        if (!$session) {
            return [];
        }

        return [
            new Channel('widget-session.' . $session->session_token),
        ];
    }

    public function broadcastAs(): string
    {
        return 'widget-message-received';
    }

    public function broadcastWith(): array
    {
        return (new MessageResource($this->message))->resolve();
    }
}
