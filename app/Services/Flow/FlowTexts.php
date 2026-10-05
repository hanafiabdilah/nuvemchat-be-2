<?php

namespace App\Services\Flow;

/**
 * The words a flow says to a customer, and nothing else.
 *
 * Translating a flow means rewriting these and leaving every other byte of
 * the graph alone — ids, branches, variables, URLs, the note an agent reads,
 * the instruction an AI follows. So the list of what counts as customer-facing
 * text lives here, by node type, and both halves of a translation read it:
 * what is pulled out to be translated and where each result is put back.
 *
 * Each path carries the length the field accepts where it has one (the
 * interactive limits are WhatsApp's), because a translation is usually longer
 * than its source and a button that no longer fits is a flow that will not
 * save.
 *
 * Deliberately absent: a condition's `value` (it is compared against what the
 * customer types, so translating it changes which branch is taken), an
 * invoice's `description` (a fiscal document, not a message), and an action's
 * `note` (read by the team).
 */
final class FlowTexts
{
    /** @var array<string, array<string, int|null>> */
    private const PATHS = [
        'message' => [
            'body' => null,
            'messages.*.body' => null,
        ],
        'response' => [
            'body' => null,
            'error_message' => null,
        ],
        'wait_response' => [
            'message' => WaitResponseNodes::MAX_MESSAGE_LENGTH,
            'error_message' => WaitResponseNodes::MAX_MESSAGE_LENGTH,
        ],
        'ai_agent' => [
            'welcoming_message' => 4000,
            'holding_message.messages.*' => null,
            'holding_message.media_messages.*' => null,
        ],
        'ai_tools' => [
            'welcoming_message' => 4000,
            'holding_message.messages.*' => null,
            'holding_message.media_messages.*' => null,
        ],
        'interactive' => [
            'header' => 60,
            'body' => 1024,
            'footer' => 60,
            'button_label' => 20,
            'buttons.*.title' => 20,
            'sections.*.title' => 24,
            'sections.*.rows.*.title' => 24,
            'sections.*.rows.*.description' => 72,
            'cards.*.body' => 160,
            'cards.*.button_label' => 20,
            'cards.*.buttons.*.title' => 20,
            'invalid_message' => 1024,
        ],
        'payment' => [
            'message' => 2000,
            'description' => 140,
        ],
        'invoice' => [
            'message' => 2000,
        ],
        'ai_media' => [
            'wait_message' => 4096,
            'caption' => 1024,
        ],
        'receipt' => [
            'message' => ReceiptNodes::MAX_MESSAGE_LENGTH,
            'invalid_message' => ReceiptNodes::MAX_MESSAGE_LENGTH,
        ],
    ];

    /**
     * Every customer-facing string in the graph.
     *
     * @param  list<array<string, mixed>>  $nodes  export-shaped nodes
     * @return list<array{node: int, path: string, text: string, max: int|null}>
     */
    public static function collect(array $nodes): array
    {
        $found = [];

        foreach (array_values($nodes) as $index => $node) {
            $data = $node['data'] ?? null;

            if (! is_array($data)) {
                continue;
            }

            foreach (self::PATHS[$node['type'] ?? ''] ?? [] as $pattern => $max) {
                foreach (self::expand($data, explode('.', $pattern)) as $path) {
                    $text = data_get($data, $path);

                    if (is_string($text) && trim($text) !== '') {
                        $found[] = ['node' => $index, 'path' => $path, 'text' => $text, 'max' => $max];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Put a string back where collect() found it.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function put(array $nodes, int $node, string $path, string $text): array
    {
        $data = $nodes[$node]['data'];
        data_set($data, $path, $text);
        $nodes[$node]['data'] = $data;

        return $nodes;
    }

    /**
     * Turn `sections.*.rows.*.title` into the concrete paths that exist.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function expand(mixed $data, array $segments, string $prefix = ''): array
    {
        if ($segments === []) {
            return [$prefix];
        }

        if (! is_array($data)) {
            return [];
        }

        $segment = array_shift($segments);

        if ($segment !== '*') {
            return array_key_exists($segment, $data)
                ? self::expand($data[$segment], $segments, ltrim("{$prefix}.{$segment}", '.'))
                : [];
        }

        $paths = [];

        foreach ($data as $key => $value) {
            array_push($paths, ...self::expand($value, $segments, ltrim("{$prefix}.{$key}", '.')));
        }

        return $paths;
    }
}
