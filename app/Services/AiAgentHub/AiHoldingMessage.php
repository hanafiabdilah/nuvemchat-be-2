<?php

namespace App\Services\AiAgentHub;

use App\Enums\Message\MessageType;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * "Um momento, estou verificando…" — the line that fills the silence between
 * the customer's message and the agent's answer.
 *
 * An AI turn is quiet for as long as it takes: the debounce window that waits
 * out the customer's typing, then the hub round-trip, then a media download
 * when a screenshot came with it. Twenty seconds of nothing is how a customer
 * decides the number is dead, and how an agent decides the bot has hung and
 * takes the thread over mid-turn.
 *
 * Three rules shape everything here:
 *
 *  1. **The text is the flow author's, never ours.** The engine does not know
 *     what language this conversation is in — the same reason InteractiveNodes'
 *     fallback carries no hard-coded instruction. A node with no list stays
 *     silent, which is also what makes this safe to ship: every flow already in
 *     production keeps behaving exactly as it did.
 *
 *  2. **It is sent only when the wait is real.** Scheduled, not sent inline: an
 *     answer that arrives in two seconds must not be preceded by a bubble
 *     apologising for a delay that never happened. See
 *     FlowExecutor::scheduleAiHoldingMessage.
 *
 *  3. **The hub never sees it.** It is our sentence, not the conversation's —
 *     and anything landing in a run's `message.content` is scanned by the hub's
 *     handoff detector, which is how a polite welcome once handed 53 of 53
 *     conversations to a human on their first turn. AiConversationContext skips
 *     anything carrying the `ai_holding` meta flag.
 */
class AiHoldingMessage
{
    /** Where the flag lives on the sent message, for everything that has to skip it. */
    public const META_FLAG = 'ai_holding';

    /**
     * Longest a node may make the customer wait before being told anything.
     *
     * Past this the setting stops being "only when it is slow" and becomes
     * "never", which the empty list already says more clearly.
     */
    public const MAX_AFTER_SECONDS = 120;

    /**
     * Most lines one list may hold.
     *
     * Variety is the point — the same sentence every turn reads as a machine
     * apologising on a loop — but a list nobody can proofread is worse than a
     * short one, and past a handful the customer never sees the difference.
     */
    public const MAX_LINES = 10;

    /** Longest a single line may be. It is one sentence, not a message. */
    public const MAX_LENGTH = 500;

    /**
     * What the hub round-trip is assumed to add, when deciding whether a wait
     * will cross the threshold.
     *
     * A guess, and deliberately a conservative one. It exists because the
     * decision has to be made *before* the model is called — the turn then
     * holds its worker for the whole round-trip, so anything that defers the
     * decision to a queued job cannot be relied on to run (see
     * FlowExecutor::scheduleAiHoldingMessage).
     *
     * Wrong in the safe direction: too high sends a courtesy to somebody whose
     * answer then arrives quickly, too low stays silent through a wait the flow
     * author asked to cover. The first is a redundant bubble; the second is the
     * bug this whole feature exists to fix.
     */
    public const ASSUMED_RUN_SECONDS = 5;

    /**
     * Platform kill switch. Off, no node sends one — the way back from a
     * channel rejecting these, without editing every flow.
     */
    public static function platformEnabled(): bool
    {
        return (bool) config('ai.holding.enabled', true);
    }

    /**
     * Which queue carries the two things that fill a wait.
     *
     * Default `default`, so this needs no ops step — but it is a real escape
     * hatch, and the reason is structural: an AI turn occupies its worker for
     * the whole hub round-trip, so anything queued behind it on the same queue
     * cannot run until the wait it was meant to cover is already over. The
     * common case is handled without a queue at all (FlowExecutor sends inline
     * when the threshold is already spent); this is for the rest. Same shape as
     * `config('queue.media')` — see docs/ai-turn-delay.md.
     */
    public static function queue(): string
    {
        $queue = trim((string) config('ai.presence_queue', 'default'));

        return $queue !== '' ? $queue : 'default';
    }

    /**
     * The node's setting, normalised.
     *
     * @return array{enabled: bool, after_seconds: int, messages: array<int, string>, media_messages: array<int, string>}
     */
    public static function config(array $nodeData): array
    {
        $raw = $nodeData['holding_message'] ?? [];
        $raw = is_array($raw) ? $raw : [];

        $messages = self::texts($raw['messages'] ?? []);
        $mediaMessages = self::texts($raw['media_messages'] ?? []);

        // Absent means off, and the key is absent on every node built before
        // this existed. A flow never starts talking on its own.
        $enabled = self::platformEnabled()
            && ($raw['enabled'] ?? true)
            && $messages !== [];

        $after = $raw['after_seconds'] ?? null;

        return [
            'enabled' => (bool) $enabled,
            'after_seconds' => max(0, min(
                is_numeric($after) ? (int) $after : (int) config('ai.holding.after_seconds', 8),
                self::MAX_AFTER_SECONDS,
            )),
            'messages' => $messages,
            'media_messages' => $mediaMessages,
        ];
    }

    /**
     * Which line goes out, given what the customer actually sent.
     *
     * The only context available for free is the shape of their message, and it
     * is the context that matters: somebody who sent a screenshot is waiting for
     * it to be looked at, somebody who recorded a voice note for it to be heard.
     * Asking a model to write this line instead would mean a second billed run
     * in front of the wait it exists to cover.
     *
     * `$seed` picks the line without storing a counter anywhere — the message id
     * is already unique and increasing, so consecutive turns rarely repeat, and
     * nothing here writes to flow state. That matters more than it looks: this
     * runs from a queued job outside the conversation lock, and a stale
     * state_data copy written back from out there would undo the watermark the
     * turn just moved.
     */
    public static function pick(array $config, bool $media, int $seed): ?string
    {
        if (! $config['enabled']) {
            return null;
        }

        $texts = $media && $config['media_messages'] !== []
            ? $config['media_messages']
            : $config['messages'];

        if ($texts === []) {
            return null;
        }

        return $texts[abs($seed) % count($texts)];
    }

    /**
     * Whether this turn is about something the customer sent rather than typed.
     *
     * Stickers are out for the same reason they are never attached to a run:
     * they are decoration, and "deixa eu ver o que você mandou" in answer to a
     * thumbs-up reads as a bot that cannot tell a reaction from a document.
     *
     * @param  Collection<int, Message>  $messages
     */
    public static function isMediaTurn(Collection $messages): bool
    {
        return $messages->contains(fn (Message $message) => in_array(
            $message->message_type,
            [
                MessageType::Image,
                MessageType::Video,
                MessageType::Audio,
                MessageType::Document,
            ],
            true,
        ));
    }

    /**
     * Trimmed, non-empty, de-duplicated lines — in the order the author wrote
     * them, because a list that reorders itself is a list nobody can proofread.
     *
     * @return array<int, string>
     */
    private static function texts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $texts = [];

        foreach ($value as $text) {
            if (! is_string($text)) {
                continue;
            }

            $text = trim($text);

            if ($text !== '' && ! in_array($text, $texts, true)) {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
