<?php

namespace App\Services\FlowAssistant;

/**
 * Makes room on the canvas for what the assistant added.
 *
 * ── The bug this exists for ──
 *
 * Insert a node between two that already exist — A → B → C becomes
 * A → B → D → C — and the model wires it correctly and then puts D exactly
 * where C is. It has no reason not to: it was told to keep the nodes it is not
 * changing, so it echoes C back with the coordinates we sent it, and picks
 * something plausible for D. The result is two nodes stacked on one spot, and
 * from the canvas it reads as a node that failed to appear.
 *
 * ── Why this is arithmetic and not a prompt instruction ──
 *
 * "Shift the downstream nodes to make room" is geometry with one right answer,
 * and coordinate arithmetic over a whole graph is exactly the kind of thing a
 * language model gets subtly wrong — intermittently, silently, and differently
 * every time. Asking it to do a calculation we can do exactly buys nothing and
 * costs a class of bug nobody can reproduce.
 *
 * ── The rule, in one sentence ──
 *
 * Nodes may not overlap, and when two do, the one further from the start moves
 * right — together with everything downstream of it, because a node that moved
 * without its children would just land on them instead.
 *
 * ⚠️ Positions that are already there are kept. A flow's layout is often the
 * work of somebody who dragged the nodes where they wanted them, and a pass
 * that recomputed every coordinate from the graph would tidy that away every
 * time the assistant was asked for anything. This only ever *adds* space.
 */
final class FlowLayout
{
    /** Horizontal distance between one step of the flow and the next. */
    public const COLUMN = 300;

    /**
     * How close two nodes have to be before they count as sitting on top of
     * each other.
     *
     * ⚠️ These are separation thresholds, **not** node dimensions, and the
     * difference matters. The first version of this used the node box (300px
     * wide, plus a 40px gap) — which is wider than the 280–300px column
     * spacing the builder and the prompt have always used, so every ordinary
     * pair of adjacent nodes read as overlapping and the pass rearranged
     * perfectly good flows. The question is not "how big is a node" but "how
     * close is too close, given the spacing this product already draws at", so
     * these sit comfortably below one column.
     *
     * Two nodes are overlapping only when they are too close on BOTH axes:
     * a branch pair one above the other is not a collision.
     */
    public const MIN_H_SEPARATION = 240;
    public const MIN_V_SEPARATION = 120;

    /**
     * A ceiling on how many times one node may be pushed.
     *
     * Flows are allowed to loop back on themselves, and a cycle makes "further
     * from the start" ill-defined — so the sweep below cannot be proven to
     * settle on every possible graph. It stops rather than spins.
     */
    private const MAX_PUSHES = 50;

    /**
     * @param  list<array<string, mixed>>  $nodes  blueprint nodes (key, position_x, position_y)
     * @param  list<array<string, mixed>>  $edges  blueprint edges (source_key, target_key)
     * @return list<array<string, mixed>>  the same nodes, moved apart
     */
    public static function resolve(array $nodes, array $edges): array
    {
        if (count($nodes) < 2) {
            return $nodes;
        }

        $byKey = [];
        foreach ($nodes as $index => $node) {
            $byKey[(string) $node['key']] = $index;
        }

        $children = [];
        foreach ($edges as $edge) {
            $source = (string) ($edge['source_key'] ?? '');
            $target = (string) ($edge['target_key'] ?? '');

            if (isset($byKey[$source], $byKey[$target])) {
                $children[$source][] = $target;
            }
        }

        $depth = self::depths($nodes, $edges, $byKey);
        $nodes = self::placeMissing($nodes, $edges, $byKey, $depth);

        // Nearest the start first, so that when two nodes clash it is always
        // the later one that gives way — which is what makes inserting a step
        // push the rest of the chain along rather than landing on top of it.
        $order = array_keys($byKey);
        usort($order, function (string $a, string $b) use ($depth, $nodes, $byKey) {
            return [$depth[$a], $nodes[$byKey[$a]]['position_x'], $nodes[$byKey[$a]]['position_y']]
                <=> [$depth[$b], $nodes[$byKey[$b]]['position_x'], $nodes[$byKey[$b]]['position_y']];
        });

        $placed = [];

        foreach ($order as $key) {
            $pushes = 0;

            while ($pushes++ < self::MAX_PUSHES) {
                $clash = self::firstClash($nodes[$byKey[$key]], $placed, $nodes, $byKey);

                if ($clash === null) {
                    break;
                }

                // Straight into the next column, rather than nudged by a few
                // pixels: the result has to look like a step in a flow, and a
                // chain of insertions must not need one pass per pixel.
                $delta = ($nodes[$byKey[$clash]]['position_x'] + self::COLUMN)
                    - $nodes[$byKey[$key]]['position_x'];

                $nodes = self::shiftSubtree($nodes, $byKey, $children, $key, $delta);
            }

            $placed[] = $key;
        }

        return $nodes;
    }

    /**
     * How many steps each node is from the start, taking the longest route.
     *
     * The longest one rather than the shortest: in a diamond, where A leads
     * both straight to C and to C by way of B, C belongs after B — putting it
     * beside B (which is what the shortest route says) puts it in B's column.
     *
     * Relaxed iteratively and capped at one pass per node, which is exact on
     * any acyclic flow and simply stops on a cyclic one.
     *
     * @return array<string, int>
     */
    private static function depths(array $nodes, array $edges, array $byKey): array
    {
        $start = null;
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'start') {
                $start = (string) $node['key'];
                break;
            }
        }

        $depth = [];
        foreach (array_keys($byKey) as $key) {
            $depth[$key] = 0;
        }

        if ($start === null) {
            return $depth;
        }

        $known = [$start => 0];

        for ($pass = 0, $limit = count($nodes); $pass < $limit; $pass++) {
            $changed = false;

            foreach ($edges as $edge) {
                $source = (string) ($edge['source_key'] ?? '');
                $target = (string) ($edge['target_key'] ?? '');

                if (! isset($known[$source]) || ! isset($byKey[$target])) {
                    continue;
                }

                $candidate = $known[$source] + 1;

                if (! isset($known[$target]) || $known[$target] < $candidate) {
                    $known[$target] = $candidate;
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        // Anything the start cannot reach sorts last. The validator refuses
        // such a blueprint, so this is only reached by a flow somebody built
        // that way by hand.
        $deepest = $known === [] ? 0 : max($known);

        foreach (array_keys($depth) as $key) {
            $depth[$key] = $known[$key] ?? $deepest + 1;
        }

        return $depth;
    }

    /**
     * Give a coordinate to anything that arrived without one.
     *
     * Placed beside its parent rather than at an arbitrary index, so a new node
     * starts in the column it belongs to and the sweep above usually has
     * nothing left to do.
     */
    private static function placeMissing(array $nodes, array $edges, array $byKey, array $depth): array
    {
        $parents = [];
        foreach ($edges as $edge) {
            $source = (string) ($edge['source_key'] ?? '');
            $target = (string) ($edge['target_key'] ?? '');

            if (isset($byKey[$source], $byKey[$target])) {
                $parents[$target][] = $source;
            }
        }

        foreach ($nodes as $index => $node) {
            $hasPosition = is_numeric($node['position_x'] ?? null) && is_numeric($node['position_y'] ?? null);

            if ($hasPosition) {
                $nodes[$index]['position_x'] = (float) $node['position_x'];
                $nodes[$index]['position_y'] = (float) $node['position_y'];
                continue;
            }

            $key = (string) $node['key'];
            $parent = null;

            foreach ($parents[$key] ?? [] as $candidate) {
                $parentNode = $nodes[$byKey[$candidate]];

                if (is_numeric($parentNode['position_x'] ?? null)) {
                    $parent = $parentNode;
                    break;
                }
            }

            $nodes[$index]['position_x'] = $parent
                ? (float) $parent['position_x'] + self::COLUMN
                : (float) ($depth[$key] * self::COLUMN);
            $nodes[$index]['position_y'] = $parent ? (float) $parent['position_y'] : 0.0;
        }

        return $nodes;
    }

    /**
     * The first already-placed node this one is sitting on, or null.
     *
     * @return string|null the clashing node's key
     */
    private static function firstClash(array $node, array $placed, array $nodes, array $byKey): ?string
    {
        foreach ($placed as $key) {
            $other = $nodes[$byKey[$key]];

            $apartHorizontally = abs($node['position_x'] - $other['position_x']) >= self::MIN_H_SEPARATION;
            $apartVertically = abs($node['position_y'] - $other['position_y']) >= self::MIN_V_SEPARATION;

            if (! $apartHorizontally && ! $apartVertically) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Move a node and everything downstream of it to the right.
     *
     * The descendants are the point. Shifting C alone to make room for D would
     * park it on whatever came after C — the same collision, one step further
     * along — so the whole tail of the flow moves together.
     */
    private static function shiftSubtree(array $nodes, array $byKey, array $children, string $root, float $delta): array
    {
        $seen = [];
        $queue = [$root];

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($seen[$key]) || ! isset($byKey[$key])) {
                continue;
            }

            $seen[$key] = true;
            $nodes[$byKey[$key]]['position_x'] += $delta;

            foreach ($children[$key] ?? [] as $child) {
                // The guard is what makes a flow that loops back on itself
                // terminate instead of walking its own cycle forever.
                if (! isset($seen[$child])) {
                    $queue[] = $child;
                }
            }
        }

        return $nodes;
    }
}
