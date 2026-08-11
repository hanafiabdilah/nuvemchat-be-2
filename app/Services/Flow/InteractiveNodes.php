<?php

namespace App\Services\Flow;

use App\Enums\Connection\Channel;
use App\Enums\Flow\NodeType;
use App\Models\Connection;
use App\Models\Flow;
use App\Models\FlowNode;
use Illuminate\Support\Collection;

/**
 * Rules and shapes shared by the Interactive flow node (reply buttons / list menu).
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

    /**
     * Every option the customer can pick, in display order, as
     * ['id' => branch key, 'title' => label].
     *
     * The id is doing three jobs at once: it is the edge's `condition_value`,
     * the source handle in the builder, and the reply id handed to WhatsApp —
     * which is how a tap comes back already mapped to the right branch.
     * Options authored before ids existed fall back to their position.
     */
    public static function options(array $data): array
    {
        $options = [];

        if (($data['interactive_type'] ?? 'button') === 'list') {
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
        $type = ($data['interactive_type'] ?? 'button') === 'list' ? 'list' : 'button';

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
     * Match a customer's reply to one of the node's options and return its
     * branch id. Tries the reply id carried by the webhook first (an actual
     * tap), then the option title, then its 1-based position — a customer who
     * types "2" instead of tapping still lands on the right branch.
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
