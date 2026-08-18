<?php

namespace App\Events\Widget;

use App\Models\LiveChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An agent is typing at a widget visitor.
 *
 * Broadcast now rather than queued, unlike the message events: a typing
 * indicator that waits its turn behind a queue worker arrives after the reply
 * it was meant to announce, which is worse than never sending it. It carries no
 * conversation content — just the fact and who — so the public widget channel
 * (already the visitor's own session token) gives nothing away.
 *
 * The widget expires it on its own; `typing: false` is a courtesy for the
 * common case of the agent sending or clearing the composer, not a guarantee
 * the visitor will ever receive one.
 */
class WidgetTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public bool $typing,
        public ?string $agentName = null,
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
        return 'widget-typing';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'typing' => $this->typing,
            'agent' => $this->agentName,
        ];
    }
}
