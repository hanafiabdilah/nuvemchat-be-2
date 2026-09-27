<?php

namespace App\Services\Flow;

use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use Illuminate\Support\Facades\DB;

/**
 * Reading a flow's graph out, and writing a whole one back.
 *
 * Extracted from FlowController so that the builder's auto-save, the export
 * file and the MCP tools are literally the same code. They were going to be the
 * same rules whatever happened — `FlowBlueprint` already saw to that — but the
 * *persistence* has corners that are easy to get subtly wrong when copied: a
 * numeric id belonging to another flow must create rather than update, edges
 * are deleted and recreated wholesale, and a node sent without an id gets no
 * entry in the map so every edge naming it disappears.
 *
 * ⚠️ `replace()` is a full replacement, not a patch. A node left out of the
 * call is deleted, along with its edges. The only safe way to edit is to read
 * the whole graph, change it, and send all of it back — which is exactly what
 * the `get_flow` / `update_flow` pair asks a model to do.
 */
final class FlowGraph
{
    /**
     * The portable envelope: database ids become local `key` strings, and edges
     * name those keys, so a graph re-imports cleanly with fresh ids.
     *
     * ⚠️ Edges are filtered on BOTH ends. A flow's `edges()` relation is
     * outgoing-only and `show()` widens it with `orWhereIn` to draw the canvas;
     * an export must not, or it carries edges pointing at nodes it does not
     * contain.
     *
     * @return array{name: string, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function export(Flow $flow): array
    {
        $flow->loadMissing('nodes');

        $nodeIds = $flow->nodes->pluck('id');

        $edges = FlowEdge::whereIn('source_node_id', $nodeIds)
            ->whereIn('target_node_id', $nodeIds)
            ->orderBy('id')
            ->get();

        return [
            'name' => $flow->name,
            'nodes' => $flow->nodes->map(fn (FlowNode $node) => [
                'key' => (string) $node->id,
                'type' => $node->type->value,
                'data' => $node->data,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
            ])->values()->all(),
            'edges' => $edges->map(fn (FlowEdge $edge) => [
                'source_key' => (string) $edge->source_node_id,
                'target_key' => (string) $edge->target_node_id,
                'condition_value' => $edge->condition_value,
            ])->values()->all(),
        ];
    }

    /**
     * Replace a flow's whole graph.
     *
     * Nodes carry the caller's own id: a numeric one that already belongs to
     * this flow is updated in place, anything else creates a row. That is how a
     * canvas can save a node it has only just drawn, and it is why the map of
     * caller id → database id comes back — a caller that throws it away creates
     * the same node again on the next save, deleting the row a running
     * conversation is standing on (`flow_states.current_node_id` is
     * `nullOnDelete`).
     *
     * @param  list<array{id?: string|null, type: string, data?: mixed, position_x: mixed, position_y: mixed}>  $nodes
     * @param  list<array{source_node_id: string, target_node_id: string, condition_value?: string|null}>  $edges
     * @return array<string, int> the caller's node ids mapped to the stored ones
     */
    public static function replace(Flow $flow, array $nodes, array $edges): array
    {
        return DB::transaction(function () use ($flow, $nodes, $edges): array {
            $existing = FlowNode::where('flow_id', $flow->id)->get()->keyBy('id');

            $storedIds = [];
            $idMap = [];

            foreach ($nodes as $node) {
                $callerId = $node['id'] ?? null;

                // A numeric id is only an update when it is one of *this*
                // flow's nodes. A numeric id from somewhere else is just a
                // string that happens to look like a number.
                $isExisting = $callerId && is_numeric($callerId) && $existing->has((int) $callerId);

                $attributes = [
                    'type' => $node['type'],
                    'data' => $node['data'] ?? null,
                    'position_x' => $node['position_x'],
                    'position_y' => $node['position_y'],
                ];

                if ($isExisting) {
                    $id = (int) $callerId;
                    $existing->get($id)->update($attributes);
                } else {
                    $id = FlowNode::create($attributes + ['flow_id' => $flow->id])->id;
                }

                $storedIds[] = $id;

                if ($callerId !== null && $callerId !== '') {
                    $idMap[(string) $callerId] = $id;
                }
            }

            $removed = $existing->keys()->diff($storedIds);

            if ($removed->isNotEmpty()) {
                FlowEdge::whereIn('source_node_id', $removed)
                    ->orWhereIn('target_node_id', $removed)
                    ->delete();

                FlowNode::whereIn('id', $removed)->delete();
            }

            // Recreated wholesale rather than diffed. Edge ids are therefore
            // never stable across a save, which is why FlowNode::outgoingEdges()
            // orders by id and why "the first edge wins" is a rule rather than
            // an accident.
            if ($storedIds !== []) {
                FlowEdge::whereIn('source_node_id', $storedIds)
                    ->orWhereIn('target_node_id', $storedIds)
                    ->delete();
            }

            foreach ($edges as $edge) {
                $source = $idMap[$edge['source_node_id']] ?? null;
                $target = $idMap[$edge['target_node_id']] ?? null;

                // An edge naming a node that was not sent is dropped in
                // silence: it cannot be drawn and it cannot be followed.
                if ($source && $target) {
                    FlowEdge::create([
                        'source_node_id' => $source,
                        'target_node_id' => $target,
                        'condition_value' => $edge['condition_value'] ?? null,
                    ]);
                }
            }

            return $idMap;
        });
    }

    /**
     * Turn an export-shaped graph (`key` / `source_key`) into the save shape
     * (`id` / `source_node_id`), so a caller holding one envelope does not have
     * to know there are two spellings of the same thing.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromExportShape(array $nodes, array $edges): array
    {
        return [
            'nodes' => array_map(fn (array $node) => [
                'id' => isset($node['key']) ? (string) $node['key'] : null,
                'type' => $node['type'] ?? null,
                'data' => $node['data'] ?? null,
                'position_x' => $node['position_x'] ?? 0,
                'position_y' => $node['position_y'] ?? 0,
            ], array_values($nodes)),
            'edges' => array_map(fn (array $edge) => [
                'source_node_id' => (string) ($edge['source_key'] ?? ''),
                'target_node_id' => (string) ($edge['target_key'] ?? ''),
                'condition_value' => $edge['condition_value'] ?? null,
            ], array_values($edges)),
        ];
    }
}
