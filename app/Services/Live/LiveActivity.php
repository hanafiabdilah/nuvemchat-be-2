<?php

namespace App\Services\Live;

use App\Events\ConversationActivity;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "What is this thread's automation doing right now", broadcast to the agents
 * watching it.
 *
 * The flow already logged every one of these transitions. The only reader was
 * `docker compose logs`, so from the panel a node waiting four minutes for a
 * reply, a five-second pause between bubbles, and a hung HTTP request all read
 * identically: nothing is happening. That is also how an agent decides the bot
 * has died and takes over a conversation mid-turn.
 *
 * Every emit is best-effort. A decoration must never be the reason a customer's
 * message goes unanswered, so the broadcast is wrapped and its failure becomes
 * a warning — the same treatment MessageService::sendTyping() gives its own.
 */
final class LiveActivity
{
    /** A node is executing. Most pass in under a millisecond. */
    public const FLOW_NODE = 'flow_node';

    /** Between the bubbles of a Message node, waiting out an authored pause. */
    public const FLOW_DELAY = 'flow_delay';

    /** A Response or Interactive node, parked until the customer answers. */
    public const FLOW_AWAITING = 'flow_awaiting';

    /** An HTTP Request node with a call in flight. */
    public const FLOW_HTTP = 'flow_http';

    /** The debounce window: waiting for the customer to stop typing. */
    public const AI_ARMED = 'ai_armed';

    /** The turn is held back until an inbound attachment finishes downloading. */
    public const AI_MEDIA = 'ai_media';

    /** A hub run is in flight. */
    public const AI_THINKING = 'ai_thinking';

    /** An agent pressed "Responder com IA" and the suggestion is generating. */
    public const AI_SUGGEST = 'ai_suggest';

    /** Nothing is running — empties the slot. */
    public const IDLE = 'idle';

    /**
     * How long a phase stays on screen with no newer event.
     *
     * These are backstops, not schedules: the phases that end normally are
     * replaced by the next emit long before their ttl. What they actually
     * protect against is a worker that died mid-turn, which would otherwise
     * leave a spinner running until the agent reloaded the page.
     */
    private const TTL = [
        self::FLOW_NODE => 10,
        self::FLOW_HTTP => 60,
        self::AI_MEDIA => 120,
        self::AI_THINKING => 180,
        self::AI_SUGGEST => 90,
    ];

    /**
     * A wait with no deadline of its own. Long, because a Response node with no
     * timeout really can sit here for an afternoon, but not unbounded — the
     * cold-load path (deriveRestingActivity on the client) reconstructs this
     * one from flow state, so expiring it costs nothing on a page that reloads.
     */
    private const AWAITING_TTL = 1800;

    public static function flowNode(Conversation $conversation, FlowNode $node): void
    {
        self::emit($conversation, self::FLOW_NODE, $node);
    }

    /**
     * @param  int  $seconds  the pause before the next bubble
     * @param  int  $index    zero-based position of the bubble being waited for
     */
    public static function flowDelay(
        Conversation $conversation,
        FlowNode $node,
        int $seconds,
        int $index,
        int $total,
    ): void {
        self::emit($conversation, self::FLOW_DELAY, $node, [
            'resume_at' => now()->addSeconds($seconds)->timestamp,
            'seconds' => $seconds,
            // 1-based for display: "balão 2 de 3" is what the agent reads.
            'index' => $index + 1,
            'total' => $total,
        ], $seconds + 5);
    }

    /**
     * @param  int|null  $timeoutSeconds  0/null when the author wired no
     *                                    no-reply branch — the node then waits
     *                                    indefinitely and there is no clock to
     *                                    show.
     */
    public static function flowAwaiting(
        Conversation $conversation,
        FlowNode $node,
        ?int $timeoutSeconds = null,
        array $detail = [],
    ): void {
        $timeoutSeconds = (int) $timeoutSeconds;

        if ($timeoutSeconds > 0) {
            $detail['timeout_at'] = now()->addSeconds($timeoutSeconds)->timestamp;
        }

        self::emit(
            $conversation,
            self::FLOW_AWAITING,
            $node,
            $detail,
            $timeoutSeconds > 0 ? $timeoutSeconds + 5 : self::AWAITING_TTL,
        );
    }

    /**
     * The host, never the URL: query strings on flow-authored endpoints carry
     * tokens and customer identifiers, and this event is read by every agent on
     * the connection.
     */
    public static function flowHttp(Conversation $conversation, FlowNode $node, string $method, string $url): void
    {
        self::emit($conversation, self::FLOW_HTTP, $node, [
            'method' => strtoupper($method),
            'host' => parse_url($url, PHP_URL_HOST) ?: null,
        ]);
    }

    public static function aiArmed(Conversation $conversation, FlowNode $node, int $seconds): void
    {
        self::emit($conversation, self::AI_ARMED, $node, [
            'resume_at' => now()->addSeconds($seconds)->timestamp,
            'seconds' => $seconds,
        ], $seconds + 5);
    }

    public static function aiMedia(Conversation $conversation, FlowNode $node): void
    {
        self::emit($conversation, self::AI_MEDIA, $node);
    }

    public static function aiThinking(Conversation $conversation, ?FlowNode $node = null): void
    {
        self::emit($conversation, self::AI_THINKING, $node);
    }

    public static function aiSuggest(Conversation $conversation, User $agent): void
    {
        self::emit($conversation, self::AI_SUGGEST, null, [
            'agent' => $agent->name,
        ]);
    }

    public static function idle(Conversation $conversation): void
    {
        self::emit($conversation, self::IDLE);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private static function emit(
        Conversation $conversation,
        string $phase,
        ?FlowNode $node = null,
        array $detail = [],
        ?int $ttl = null,
    ): void {
        if (! config('live.activity_enabled', true)) {
            return;
        }

        $connection = $conversation->connection;

        if (! $connection) {
            return;
        }

        try {
            broadcast(new ConversationActivity(
                conversationId: (int) $conversation->id,
                connectionId: (int) $connection->id,
                tenantId: (int) $connection->tenant_id,
                phase: $phase,
                ttl: $ttl ?? self::TTL[$phase] ?? 30,
                node: $node ? self::describeNode($node) : null,
                detail: $detail,
            ));
        } catch (Throwable $e) {
            // Reverb is down, or the payload was refused. The flow carries on:
            // losing the indicator is a cosmetic failure, losing the reply is
            // not.
            Log::warning('LiveActivity: could not broadcast activity', [
                'conversation_id' => $conversation->id,
                'phase' => $phase,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The node as the panel needs to name it.
     *
     * `label` is whatever the author typed on the canvas, when they typed
     * anything. It is not translated and not defaulted here: the server does
     * not know the reader's language, so a node with no label travels as null
     * and the client names it from `type` — the same split INFO_COPY uses for
     * system notes.
     *
     * @return array<string, mixed>
     */
    private static function describeNode(FlowNode $node): array
    {
        $label = ($node->data ?? [])['label'] ?? null;
        $label = is_string($label) ? trim($label) : '';

        return [
            'id' => (int) $node->id,
            'type' => $node->type->value,
            'label' => $label !== '' ? mb_strimwidth($label, 0, 60, '…') : null,
        ];
    }
}
