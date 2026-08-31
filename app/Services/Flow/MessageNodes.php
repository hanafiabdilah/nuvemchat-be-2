<?php

namespace App\Services\Flow;

/**
 * The Message node's shape.
 *
 * A Message node used to be one message: `body` + `message_type` +
 * `attachment_url` + a single `delay`. People do not write that way — a
 * greeting, then the price list, then "posso te ajudar com mais alguma coisa?"
 * is three bubbles, and building it meant three nodes wired in a line, each one
 * a place the canvas could break.
 *
 * So the node now holds a list. `messages` is the truth when it is there; the
 * flat fields are read only for nodes saved before it existed. Both shapes go
 * through items() and nothing downstream has to know which one it got.
 *
 * The frontend mirrors this in `lib/messageNodeItems.ts` — the same reason
 * InteractiveNodes has a mirror: the builder decides what an author is shown,
 * and a builder that disagrees with the engine writes flows that do not run.
 */
class MessageNodes
{
    /**
     * Bubbles one node may send.
     *
     * Not a technical limit — a bound on how much of a conversation one node is
     * allowed to monopolise. Past this, the thing being built is a script, and
     * a script belongs in more than one node.
     */
    public const MAX_ITEMS = 10;

    /**
     * Longest pause before a single bubble.
     *
     * A pause is there to make a sequence read like typing, not to schedule
     * anything: five minutes is already far past that, and anything longer is
     * a customer who has put the phone down.
     */
    public const MAX_DELAY_SECONDS = 300;

    public const MESSAGE_TYPES = ['text', 'image', 'audio', 'video', 'document'];

    /**
     * The bubbles this node sends, in order, each as
     * ['message_type', 'body', 'attachment_url', 'delay'].
     *
     * `delay` is the pause *before* that bubble — which is what the single
     * legacy `delay` always meant, so an old node keeps its exact timing.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public static function items(array $data): array
    {
        $raw = $data['messages'] ?? null;

        if (!is_array($raw) || $raw === []) {
            $raw = [[
                'message_type' => $data['message_type'] ?? 'text',
                'body' => $data['body'] ?? '',
                'attachment_url' => $data['attachment_url'] ?? null,
                'delay' => $data['delay'] ?? 0,
            ]];
        }

        $items = [];

        foreach (array_values($raw) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $item = self::normalizeItem($entry);

            if (self::isSendable($item)) {
                $items[] = $item;
            }

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function normalizeItem(array $entry): array
    {
        $type = (string) ($entry['message_type'] ?? 'text');
        $url = $entry['attachment_url'] ?? null;

        return [
            'message_type' => in_array($type, self::MESSAGE_TYPES, true) ? $type : 'text',
            'body' => (string) ($entry['body'] ?? ''),
            'attachment_url' => is_string($url) && trim($url) !== '' ? $url : null,
            'delay' => self::clampDelay($entry['delay'] ?? 0),
        ];
    }

    /**
     * Whether this bubble has anything in it.
     *
     * Half-finished bubbles are the normal state of a node being edited — the
     * builder auto-saves between keystrokes — so an empty one is skipped at
     * send time rather than rejected at save time, exactly like an interactive
     * node with no options yet.
     *
     * @param  array<string, mixed>  $item
     */
    public static function isSendable(array $item): bool
    {
        if (($item['attachment_url'] ?? null) !== null) {
            return true;
        }

        return trim((string) ($item['body'] ?? '')) !== '';
    }

    /**
     * Whether sending this node means waiting at any point.
     *
     * The answer decides where the node runs: with no pauses it stays inline in
     * the webhook, exactly as it always did; with one it moves to the queue,
     * because a webhook that sleeps is a webhook Meta and Telegram both retry.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function hasDelay(array $items): bool
    {
        foreach ($items as $item) {
            if (($item['delay'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function totalDelay(array $items): int
    {
        return array_sum(array_map(fn ($item) => (int) ($item['delay'] ?? 0), $items));
    }

    public static function clampDelay(mixed $delay): int
    {
        $seconds = (int) $delay;

        return max(0, min(self::MAX_DELAY_SECONDS, $seconds));
    }
}
