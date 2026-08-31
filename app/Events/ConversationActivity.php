<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * What the automation is doing to this thread, right now.
 *
 * One event covers both the flow and the AI because they are the same thing
 * seen from different distances: the AI agent *is* a flow node. Two events
 * would put two indicators on one row, competing to describe a single state.
 * So there is one slot per conversation, the newest phase wins, and
 * `phase: idle` empties it.
 *
 * Broadcast now rather than queued, for the reason WidgetTyping is: a signal
 * that waits behind the queue worker arrives after the thing it announced. The
 * whole point is to be visible *during* the pause.
 *
 * Carries metadata only — node type, phase, when it resumes. No message body
 * ever rides this event; the rule LiveMonitor already holds itself to.
 */
class ConversationActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $node     {id, type, label}
     * @param  array<string, mixed>       $detail   phase-specific extras
     */
    public function __construct(
        public int $conversationId,
        public int $connectionId,
        public int $tenantId,
        public string $phase,
        public int $ttl,
        public ?array $node = null,
        public array $detail = [],
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
        return 'conversation-activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'connection_id' => $this->connectionId,
            'phase' => $this->phase,
            // Seconds the client should keep showing this without a newer
            // event. The backstop for a worker that died mid-turn: without it,
            // a spinner started by a job that never finished never stops.
            'ttl' => $this->ttl,
            'node' => $this->node,
            'detail' => $this->detail,
            'at' => now()->timestamp,
        ];
    }
}
