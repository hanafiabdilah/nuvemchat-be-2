<?php

namespace App\Services\Flow;

/**
 * Rewrites flows saved before the Wait for reply node existed, so none of them
 * changes behaviour now that the two older ways of waiting are gone.
 *
 * Two shapes are rewritten:
 *
 *  1. A Message node whose `wait_for_reply` was on — the default, so most of
 *     them. It sent its bubbles and parked the flow on the *next* node until the
 *     customer wrote. Now: Message → Wait for reply → (replied) that next node.
 *     The wait stores nothing and has no limit, exactly like the switch.
 *
 *  2. A Response node with a no-reply limit. It asked, validated, stored, and
 *     gave up through `timeout` once the limit ran out. Now: Message (the
 *     question) → Wait for reply holding the same variable, validation, error
 *     message and limit. The Response node itself becomes the wait — same key,
 *     so same id — which is what lets the migration keep a conversation parked
 *     on it in its place.
 *
 * Plus the leftovers the removal would otherwise turn into bugs: the switch is
 * dropped from every Message node, and a Response node without a limit (which
 * has one output now) loses its branch values and any `timeout` edge — an edge
 * that could never fire before, and that as a plain edge could be the one
 * taken.
 *
 * Works on blueprint-shaped arrays — nodes with `key`, `type`, `data`,
 * `position_x`, `position_y`; edges with `source_key`, `target_key`,
 * `condition_value` — so one rewrite serves the migration (where keys are node
 * ids) and the importer (a file exported before the change).
 */
final class LegacyWaitUpgrade
{
    /** How far the nodes after an inserted one move to make room: one column. */
    private const COLUMN = 300;

    /** The Response node's own ceiling on its limit, before it was removed. */
    private const LEGACY_RESPONSE_MAX_TIMEOUT = 86400;

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     edges: list<array<string, mixed>>,
     *     changed: bool,
     *     questions: array<string, string>
     * } `questions` maps each converted Response node's key to the key of the
     *   Message node inserted to ask its question.
     */
    public static function upgrade(array $nodes, array $edges): array
    {
        $nodes = array_values($nodes);
        $edges = array_values($edges);
        $changed = false;
        $questions = [];

        $taken = [];
        foreach ($nodes as $node) {
            $taken[(string) $node['key']] = true;
        }

        $counter = 0;
        $newKey = function (string $prefix) use (&$taken, &$counter): string {
            do {
                $key = $prefix . '-legacy-' . (++$counter);
            } while (isset($taken[$key]));

            $taken[$key] = true;

            return $key;
        };

        // Left to right, decided up front: an insertion moves the nodes after
        // it, and the order must not change underneath the loop.
        $order = array_keys($nodes);
        usort($order, fn (int $a, int $b) => [(float) ($nodes[$a]['position_x'] ?? 0), $a]
            <=> [(float) ($nodes[$b]['position_x'] ?? 0), $b]);

        foreach ($order as $index) {
            $node = $nodes[$index];
            $key = (string) $node['key'];
            $type = $node['type'] ?? null;
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            if ($type === 'message') {
                // Read exactly as the engine read it: absent meant on.
                $waited = ($data['wait_for_reply'] ?? true) !== false;

                if (array_key_exists('wait_for_reply', $data)) {
                    unset($data['wait_for_reply']);
                    $nodes[$index]['data'] = $data;
                    $changed = true;
                }

                $outgoing = self::outgoing($edges, $key);

                // With nothing after it the switch ended the flow, and a message
                // node with nothing after it now does the same.
                if (!$waited || $outgoing === []) {
                    continue;
                }

                $target = self::indexOf($nodes, (string) $edges[$outgoing[0]]['target_key']);
                $waitKey = $newKey('wait');

                $x = $target !== null ? $nodes[$target]['position_x'] : ($node['position_x'] ?? 0) + self::COLUMN;
                $y = $target !== null ? $nodes[$target]['position_y'] : ($node['position_y'] ?? 0);

                if ($target !== null) {
                    self::shiftDownstream($nodes, $edges, (string) $nodes[$target]['key'], [$key]);
                }

                foreach ($outgoing as $edgeIndex) {
                    $edges[$edgeIndex]['source_key'] = $waitKey;
                    $edges[$edgeIndex]['condition_value'] = WaitResponseNodes::BRANCH_REPLIED;
                }

                $nodes[] = [
                    'key' => $waitKey,
                    'type' => 'wait_response',
                    'data' => WaitResponseNodes::defaults(),
                    'position_x' => $x,
                    'position_y' => $y,
                ];
                $edges[] = ['source_key' => $key, 'target_key' => $waitKey, 'condition_value' => null];

                $changed = true;
                continue;
            }

            if ($type !== 'response') {
                continue;
            }

            $limit = (int) ($data['timeout_seconds'] ?? 0);
            $outgoing = self::outgoing($edges, $key);

            if ($limit <= 0) {
                if (array_key_exists('timeout_seconds', $data)) {
                    unset($data['timeout_seconds']);
                    $nodes[$index]['data'] = $data;
                    $changed = true;
                }

                $drop = [];

                foreach ($outgoing as $edgeIndex) {
                    $value = $edges[$edgeIndex]['condition_value'] ?? null;

                    if ($value === WaitResponseNodes::BRANCH_TIMEOUT) {
                        $drop[] = $edgeIndex;
                        $changed = true;
                    } elseif ($value !== null) {
                        $edges[$edgeIndex]['condition_value'] = null;
                        $changed = true;
                    }
                }

                if ($drop !== []) {
                    $edges = array_values(array_diff_key($edges, array_flip($drop)));
                }

                continue;
            }

            $limit = min($limit, self::LEGACY_RESPONSE_MAX_TIMEOUT);
            $questionKey = $newKey('ask');
            $x = $node['position_x'] ?? 0;
            $y = $node['position_y'] ?? 0;

            // The question takes the node's place; the wait and everything after
            // it moves along by a column.
            self::shiftDownstream($nodes, $edges, $key, []);

            // Whatever led to the question — including an edge looping back to
            // "ask again" — now leads to the message that asks it.
            foreach ($edges as $edgeIndex => $edge) {
                if ((string) $edge['target_key'] === $key) {
                    $edges[$edgeIndex]['target_key'] = $questionKey;
                }
            }

            foreach ($outgoing as $edgeIndex) {
                if (($edges[$edgeIndex]['condition_value'] ?? null) === null) {
                    // Saved before the Response node had two outputs: "replied".
                    $edges[$edgeIndex]['condition_value'] = WaitResponseNodes::BRANCH_REPLIED;
                }
            }

            $messageType = (string) ($data['message_type'] ?? 'text');
            $bubble = [
                'message_type' => in_array($messageType, MessageNodes::MESSAGE_TYPES, true) ? $messageType : 'text',
                'body' => (string) ($data['body'] ?? ''),
                'delay' => 0,
            ];

            if (is_string($data['attachment_url'] ?? null) && trim($data['attachment_url']) !== '') {
                $bubble['attachment_url'] = $data['attachment_url'];
            }

            $nodes[] = [
                'key' => $questionKey,
                'type' => 'message',
                'data' => ['messages' => [$bubble]],
                'position_x' => $x,
                'position_y' => $y,
            ];
            $edges[] = ['source_key' => $questionKey, 'target_key' => $key, 'condition_value' => null];

            $validation = $data['validation'] ?? 'any';

            $nodes[$index]['type'] = 'wait_response';
            $nodes[$index]['data'] = array_filter([
                // The author's own name for the step, when they gave it one.
                'label' => $data['label'] ?? null,
            ], fn ($value) => $value !== null) + [
                'message' => '',
                'variable_key' => (string) ($data['variable_key'] ?? ''),
                'timeout_seconds' => $limit,
                'timeout_unit' => WaitResponseNodes::unitFor($limit),
                'buffer_seconds' => 0,
                'validation' => in_array($validation, WaitResponseNodes::VALIDATIONS, true) ? $validation : 'any',
                'error_message' => (string) ($data['error_message'] ?? ''),
            ];

            $questions[$key] = $questionKey;
            $changed = true;
        }

        return [
            'nodes' => $nodes,
            'edges' => array_values($edges),
            'changed' => $changed,
            'questions' => $questions,
        ];
    }

    /** @return list<int> indexes of the edges leaving `$key`, in order */
    private static function outgoing(array $edges, string $key): array
    {
        $indexes = [];

        foreach ($edges as $index => $edge) {
            if ((string) $edge['source_key'] === $key) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    private static function indexOf(array $nodes, string $key): ?int
    {
        foreach ($nodes as $index => $node) {
            if ((string) $node['key'] === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Move a node and everything reachable from it one column right.
     *
     * Only what sits at or right of the node: a flow that loops back to its
     * menu reaches the menu from here, and dragging that left-hand node along
     * would tear the drawing apart rather than make room.
     *
     * @param  list<string>  $exclude
     */
    private static function shiftDownstream(array &$nodes, array $edges, string $fromKey, array $exclude): void
    {
        $from = self::indexOf($nodes, $fromKey);

        if ($from === null) {
            return;
        }

        $minX = (float) ($nodes[$from]['position_x'] ?? 0);

        $children = [];
        foreach ($edges as $edge) {
            $children[(string) $edge['source_key']][] = (string) $edge['target_key'];
        }

        $seen = [$fromKey => true];
        $queue = [$fromKey];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($children[$current] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        foreach ($nodes as $index => $node) {
            $key = (string) $node['key'];

            if (!isset($seen[$key]) || in_array($key, $exclude, true)) {
                continue;
            }

            if ((float) ($node['position_x'] ?? 0) < $minX) {
                continue;
            }

            $nodes[$index]['position_x'] = ($node['position_x'] ?? 0) + self::COLUMN;
        }
    }
}
