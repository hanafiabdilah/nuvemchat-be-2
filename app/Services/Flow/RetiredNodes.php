<?php

namespace App\Services\Flow;

/**
 * Node types the builder no longer has, cut out of a flow that still carries
 * them.
 *
 * Today that is `lead`, removed with the Leads module. A Lead node never
 * changed where a conversation went — it updated the funnel and always carried
 * on to its one output — so removing it loses nothing about the route: whatever
 * led into it now leads to where it led. The migration that removed the module
 * did this to stored flows; this does it to a file exported before, so an old
 * export imports as the flow it was instead of failing validation.
 *
 * Works on the export shape (nodes with `key`, edges with `source_key`,
 * `target_key`, `condition_value`), the same one {@see LegacyWaitUpgrade} uses.
 */
final class RetiredNodes
{
    public const TYPES = ['lead'];

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function strip(array $nodes, array $edges): array
    {
        $nodes = array_values($nodes);
        $edges = array_values($edges);

        // One node per pass: a retired node pointing at another one resolves
        // on the next pass, at the second node's own successor.
        while (($index = self::firstRetired($nodes)) !== null) {
            $key = (string) $nodes[$index]['key'];
            $next = null;

            foreach ($edges as $edge) {
                if ((string) $edge['source_key'] === $key && (string) $edge['target_key'] !== $key) {
                    $next = (string) $edge['target_key'];
                    break;
                }
            }

            $kept = [];

            foreach ($edges as $edge) {
                $from = (string) $edge['source_key'];
                $to = (string) $edge['target_key'];

                if ($from === $key) {
                    continue;
                }

                if ($to === $key) {
                    if ($next === null) {
                        continue;
                    }

                    $edge['target_key'] = $next;
                }

                $kept[] = $edge;
            }

            $edges = $kept;
            array_splice($nodes, $index, 1);
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private static function firstRetired(array $nodes): ?int
    {
        foreach ($nodes as $index => $node) {
            if (in_array($node['type'] ?? null, self::TYPES, true)) {
                return $index;
            }
        }

        return null;
    }
}
