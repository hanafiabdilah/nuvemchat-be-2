<?php

namespace App\Events\Widget;

use App\Models\LiveChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An agent has read what the visitor wrote.
 *
 * The widget already draws the second tick from `read_at` — but only on the
 * messages it happens to fetch, so a visitor sitting with the panel open never
 * saw it turn until they reloaded. This is the live half.
 *
 * Broadcast now, like WidgetTyping and for the same reason: it is small, it is
 * only interesting while the visitor is still looking, and behind a queue
 * worker it would arrive after the reply it was meant to precede. It names
 * message ids the visitor already has and nothing else, so the public widget
 * channel — the visitor's own session token — gives nothing away.
 */
class WidgetMessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public int $conversationId,
        public array $messageIds,
        public string $readAt,
    ) {
        //
    }

    public function broadcastOn(): array
    {
        $session = LiveChatSession::where('conversation_id', $this->conversationId)->first();

        if (! $session) {
            return [];
        }

        return [
            new Channel('widget-session.'.$session->session_token),
        ];
    }

    public function broadcastAs(): string
    {
        return 'widget-messages-read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_ids' => $this->messageIds,
            'read_at' => $this->readAt,
        ];
    }
}
