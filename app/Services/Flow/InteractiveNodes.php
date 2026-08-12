<?php

namespace App\Services\Flow;

use App\Enums\Connection\Channel;
use App\Enums\Flow\NodeType;
use App\Models\Connection;
use App\Models\Flow;
use App\Models\FlowNode;
use Illuminate\Support\Collection;

/**
 * Rules and shapes shared by the Interactive flow node (reply buttons, a list
 * menu, or a media carousel).
 *
 * Interactive messages are a WhatsApp Cloud API feature — no other channel we
 * speak has a native equivalent — so a flow containing one of these nodes may
 * only be wired to WhatsApp Official connections. That constraint is enforced
 * from both ends: saving the flow, and pointing a connection at it.
 */
class InteractiveNodes
{
    /** The only channel able to run these nodes. */
    public const CHANNEL = Channel::WhatsappOfficial;

    /** The interactive kinds a node may be authored as. */
    public const TYPES = ['button', 'list', 'carousel'];

    /** A carousel needs at least this many cards before the Cloud API accepts it. */
    public const CAROUSEL_MIN_CARDS = 2;

    public const CAROUSEL_MAX_CARDS = 10;

    /** The node's interactive kind, defaulting anything unrecognised to buttons. */
    public static function type(array $data): string
    {
        $type = (string) ($data['interactive_type'] ?? 'button');

        return in_array($type, self::TYPES, true) ? $type : 'button';
    }

    /**
     * Which button style a carousel's cards carry. Cards may hold either one
     * link-out button or a row of quick replies — never a mix, and the same
     * choice across every card, which is why it lives on the node, not the card.
     */
    public static function cardButtonType(array $data): string
    {
        return ($data['card_button_type'] ?? 'quick_reply') === 'cta_url' ? 'cta_url' : 'quick_reply';
    }

    /**
     * Every option the customer can pick, in display order, as
     * ['id' => branch key, 'title' => label].
     *
     * The id is doing three jobs at once: it is the edge's `condition_value`,
     * the source handle in the builder, and the reply id handed to WhatsApp —
     * which is how a tap comes back already mapped to the right branch.
     * Options authored before ids existed fall back to their position.
     *
     * A carousel's options are its cards' quick replies, flattened in card
     * order. Link-out cards produce none: tapping one leaves WhatsApp, so
     * there is nothing to branch on.
     */
    public static function options(array $data): array
    {
        $options = [];
        $type = self::type($data);

        if ($type === 'carousel') {
            if (self::cardButtonType($data) !== 'quick_reply') {
                return [];
            }

            foreach (array_values((array) ($data['cards'] ?? [])) as $ci => $card) {
                foreach (array_values((array) ($card['buttons'] ?? [])) as $bi => $button) {
                    $title = trim((string) ($button['title'] ?? ''));

                    if ($title === '') {
                        continue;
                    }

                    $options[] = [
                        'id' => self::optionId($button, 'card_' . ($ci + 1) . '_btn_' . ($bi + 1)),
                        'title' => $title,
                    ];
                }
            }

            return $options;
        }

        if ($type === 'list') {
            foreach (array_values((array) ($data['sections'] ?? [])) as $si => $section) {
                foreach (array_values((array) ($section['rows'] ?? [])) as $ri => $row) {
                    $title = trim((string) ($row['title'] ?? ''));

                    if ($title === '') {
                        continue;
                    }

                    $options[] = [
                        'id' => self::optionId($row, 'row_' . ($si + 1) . '_' . ($ri + 1)),
                        'title' => $title,
                    ];
                }
            }

            return $options;
        }

        foreach (array_values((array) ($data['buttons'] ?? [])) as $i => $button) {
            $title = trim((string) ($button['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $options[] = [
                'id' => self::optionId($button, 'btn_' . ($i + 1)),
                'title' => $title,
            ];
        }

        return $options;
    }

    /**
     * Translate node data into the flat payload MessageService::sendInteractive
     * expects, carrying the node's own option ids so the customer's reply comes
     * back with a branch key we recognise. Empty options are dropped — a half
     * finished node should not fail the whole send with a Cloud API error.
     */
    public static function sendPayload(array $data): array
    {
        $type = self::type($data);

        // A carousel carries no header or footer of its own — the Cloud API
        // rejects them — so it is built from the body and the cards alone.
        if ($type === 'carousel') {
            return [
                'interactive_type' => 'carousel',
                'body' => (string) ($data['body'] ?? ''),
                'card_button_type' => self::cardButtonType($data),
                'cards' => self::cards($data),
            ];
        }

        $payload = array_filter([
            'interactive_type' => $type,
            'body' => (string) ($data['body'] ?? ''),
            'header' => trim((string) ($data['header'] ?? '')) ?: null,
            'footer' => trim((string) ($data['footer'] ?? '')) ?: null,
        ], fn ($value) => $value !== null);

        if ($type === 'button') {
            $payload['buttons'] = array_map(
                fn (array $option) => ['id' => $option['id'], 'title' => $option['title']],
                self::options($data)
            );

            return $payload;
        }

        $payload['button_label'] = trim((string) ($data['button_label'] ?? '')) ?: 'Menu';
        $payload['sections'] = [];

        foreach (array_values((array) ($data['sections'] ?? [])) as $si => $section) {
            $rows = [];

            foreach (array_values((array) ($section['rows'] ?? [])) as $ri => $row) {
                $title = trim((string) ($row['title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                $rows[] = array_filter([
                    'id' => self::optionId($row, 'row_' . ($si + 1) . '_' . ($ri + 1)),
                    'title' => $title,
                    'description' => trim((string) ($row['description'] ?? '')) ?: null,
                ], fn ($value) => $value !== null);
            }

            if ($rows !== []) {
                $payload['sections'][] = array_filter([
                    'title' => trim((string) ($section['title'] ?? '')) ?: null,
                    'rows' => $rows,
                ], fn ($value) => $value !== null);
            }
        }

        return $payload;
    }

    /**
     * The carousel's cards, normalised into the flat shape the send handler
     * turns into Cloud API `action.cards`, in display order.
     *
     * A card with no media is dropped: the Cloud API refuses a card without an
     * image or video header, and one half-finished card should not cost the
     * whole send. Card indexes are assigned by the handler from the surviving
     * order, so a hole in the middle never leaves a gap.
     */
    public static function cards(array $data): array
    {
        $buttonType = self::cardButtonType($data);
        $cards = [];

        foreach (array_values((array) ($data['cards'] ?? [])) as $ci => $card) {
            $url = trim((string) ($card['header_url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $entry = [
                'header_type' => ($card['header_type'] ?? 'image') === 'video' ? 'video' : 'image',
                'header_url' => $url,
                'body' => trim((string) ($card['body'] ?? '')),
            ];

            if ($buttonType === 'cta_url') {
                $entry['button_label'] = trim((string) ($card['button_label'] ?? '')) ?: 'Open';
                $entry['button_url'] = trim((string) ($card['button_url'] ?? ''));
            } else {
                $entry['buttons'] = [];

                foreach (array_values((array) ($card['buttons'] ?? [])) as $bi => $button) {
                    $title = trim((string) ($button['title'] ?? ''));

                    if ($title === '') {
                        continue;
                    }

                    $entry['buttons'][] = [
                        'id' => self::optionId($button, 'card_' . ($ci + 1) . '_btn_' . ($bi + 1)),
                        'title' => $title,
                    ];
                }
            }

            $cards[] = $entry;
        }

        return $cards;
    }

    /**
     * Does this node stop and wait for the customer, the way a Response node
     * does? Everything branches on a tap except a link-out carousel, which has
     * no reply to wait for — the flow carries straight on to the next node.
     */
    public static function awaitsReply(array $data): bool
    {
        return self::type($data) !== 'carousel' || self::cardButtonType($data) === 'quick_reply';
    }

    /**
     * Is there enough here to put in front of a customer? A node still being
     * authored is skipped rather than failing the send with a Cloud API error.
     */
    public static function isSendable(array $data): bool
    {
        if (trim((string) ($data['body'] ?? '')) === '') {
            return false;
        }

        if (self::type($data) !== 'carousel') {
            return self::options($data) !== [];
        }

        $cards = self::cards($data);

        if (count($cards) < self::CAROUSEL_MIN_CARDS) {
            return false;
        }

        // Meta rejects a carousel whose cards disagree, so a single unfinished
        // card makes the whole thing unsendable. Better to notice here — and
        // fall through to the next node — than to stall on a Cloud API error.
        $linkOut = self::cardButtonType($data) === 'cta_url';

        foreach ($cards as $card) {
            $complete = $linkOut
                ? ($card['button_url'] ?? '') !== ''
                : ($card['buttons'] ?? []) !== [];

            if (!$complete) {
                return false;
            }
        }

        return true;
    }

    /**
     * The reply a customer's tap carried, as ['id' => …, 'title' => …].
     *
     * Buttons and list rows have always arrived under their own keys, and a
     * carousel's quick reply lands in the same shape — so this matches on the
     * `_reply` suffix rather than naming every kind Meta might add next.
     */
    public static function replyFromWebhook(?array $interactive): ?array
    {
        foreach ((array) $interactive as $key => $value) {
            if (!is_string($key) || !str_ends_with($key, '_reply') || !is_array($value)) {
                continue;
            }

            $id = $value['id'] ?? null;
            $title = $value['title'] ?? null;

            if ($id === null && $title === null) {
                continue;
            }

            return [
                'id' => $id !== null ? (string) $id : null,
                'title' => $title !== null ? (string) $title : null,
                'description' => isset($value['description']) ? (string) $value['description'] : null,
            ];
        }

        return null;
    }

    /**
     * Match a customer's reply to one of the node's options and return its
     * branch id. Tries the reply id carried by the webhook first (an actual
     * tap), then the option title, then its 1-based position — a customer who
     * types "2" instead of tapping still lands on the right branch.
     *
     * On a carousel the titles repeat across cards ("Learn more" on each), so
     * only the reply id truly disambiguates; the text fallbacks land on the
     * first card that matches, which is the best a typed answer can do.
     */
    public static function matchOption(array $data, ?string $replyId, string $userInput): ?string
    {
        $options = self::options($data);

        if ($options === []) {
            return null;
        }

        if ($replyId !== null && $replyId !== '') {
            foreach ($options as $option) {
                if ($option['id'] === $replyId) {
                    return $option['id'];
                }
            }
        }

        $input = trim($userInput);

        if ($input === '') {
            return null;
        }

        foreach ($options as $option) {
            if (mb_strtolower($option['title']) === mb_strtolower($input)) {
                return $option['id'];
            }
        }

        if (ctype_digit($input)) {
            $index = (int) $input - 1;

            if (isset($options[$index])) {
                return $options[$index]['id'];
            }
        }

        return null;
    }

    /** Does this flow contain at least one interactive node? */
    public static function flowUsesInteractive(int|string $flowId): bool
    {
        return FlowNode::where('flow_id', $flowId)
            ->where('type', NodeType::Interactive)
            ->exists();
    }

    /** Same question, answered from an unsaved set of nodes coming off the builder. */
    public static function payloadUsesInteractive(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === NodeType::Interactive->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Connections wired to this flow that cannot run interactive nodes — the
     * reason a save (or an assignment) is refused, and what we name in the error.
     */
    public static function conflictingConnections(Flow|int|string $flow): Collection
    {
        $flowId = $flow instanceof Flow ? $flow->id : $flow;

        return Connection::where('flow_id', $flowId)
            ->where('channel', '!=', self::CHANNEL)
            ->get(['id', 'name', 'channel']);
    }

    /** Human-readable "telegram (Support), instagram (Shop)" for error messages. */
    public static function describeConnections(Collection $connections): string
    {
        return $connections
            ->map(fn (Connection $connection) => $connection->channel->value . ' (' . $connection->name . ')')
            ->implode(', ');
    }

    /** An option's stored id, or a positional one for options authored without it. */
    private static function optionId(array $option, string $fallback): string
    {
        $id = trim((string) ($option['id'] ?? ''));

        return $id !== '' ? $id : $fallback;
    }
}
