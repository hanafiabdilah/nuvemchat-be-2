<?php

namespace App\Services\Mcp\Tools\Concerns;

use App\Models\Flow;
use App\Models\User;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowGraph;
use App\Services\FlowAssistant\FlowLayout;
use App\Services\Mcp\Tools\ToolException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The parts every flow tool needs: find a flow, and turn a model's graph into
 * something the writer will accept.
 *
 * ⚠️ Every rule here comes from `FlowBlueprint` or `FlowLayout`. Nothing is
 * restated. A second description of what a valid flow looks like would be
 * correct on the day it was written and quietly wrong after the next node type,
 * and the symptom would be a model producing flows the builder refuses.
 */
trait WorksWithFlows
{
    protected function findFlow(mixed $id, User $user): Flow
    {
        $flow = Flow::with('nodes')
            ->where('tenant_id', $user->tenant_id)
            ->find((int) $id);

        if (! $flow) {
            // Not "forbidden": from here another workspace's flow and a deleted
            // one are the same thing, and saying which would answer a question
            // nobody is entitled to ask.
            throw new ToolException("There is no flow with id {$id} in this workspace. Call list_flows to see what there is.");
        }

        return $flow;
    }

    /**
     * Check a graph's shape and lay it out, without touching the database.
     *
     * Positions are optional on the way in. A model asked to place nodes on a
     * canvas will often leave them out or pile them up, and `FlowLayout` — the
     * same pass the in-app assistant runs — puts a node without a position
     * beside its parent and pushes a subtree aside rather than letting two
     * nodes land on the same point. A flow drawn on top of itself reads as
     * nodes that failed to appear.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function prepareGraph(array $arguments): array
    {
        $input = [
            'nodes' => $arguments['nodes'] ?? null,
            'edges' => $arguments['edges'] ?? [],
        ];

        $validator = Validator::make($input, [
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.key' => ['required', 'string', 'max:64'],
            'nodes.*.type' => ['required', 'string', Rule::in(FlowBlueprint::NODE_TYPES)],
            'nodes.*.data' => ['nullable'],
            'nodes.*.position_x' => ['nullable', 'numeric'],
            'nodes.*.position_y' => ['nullable', 'numeric'],
            'edges' => ['present', 'array'],
            'edges.*.source_key' => ['required', 'string'],
            'edges.*.target_key' => ['required', 'string'],
            'edges.*.condition_value' => ['nullable', 'string', FlowBlueprint::BRANCH_VALUE_PATTERN],
        ]);

        if ($validator->fails()) {
            throw new ToolException(
                'The graph is not in the expected shape.',
                $this->flatten($validator->errors()->toArray()),
            );
        }

        $nodes = array_values($input['nodes']);
        $edges = array_values($input['edges']);

        // Per-node rules: the type's own fields, and the tenant-scoped `exists`
        // checks that make an invented tag or agent id fail here rather than at
        // the moment a customer is waiting.
        try {
            FlowBlueprint::validateNodes($nodes);
        } catch (ValidationException $e) {
            throw new ToolException(
                'Some nodes are not valid.',
                $this->describeNodeErrors($e, $nodes),
            );
        }

        $deduped = FlowBlueprint::dedupeEdges($edges, 'source_key');

        return [
            'nodes' => FlowLayout::resolve($nodes, $deduped['edges']),
            'edges' => $deduped['edges'],
            'dropped_edges' => $deduped['dropped'],
        ];
    }

    /**
     * A flow may not continue into itself.
     *
     * The same check `FlowController::assertNoSelfJump()` makes, restated
     * because it is one comparison and cannot drift: an edge back to an earlier
     * node is the way to loop, and `go_to_flow` pointing here would hand the
     * conversation to this flow's start for ever.
     */
    protected function assertNoSelfJump(array $nodes, int $flowId): void
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'go_to_flow'
                && (int) (($node['data'] ?? [])['flow_id'] ?? 0) === $flowId) {
                throw new ToolException(
                    "Node \"{$node['key']}\" continues into this same flow. To go back to an earlier step, draw an edge to it instead."
                );
            }
        }
    }

    /**
     * Turn a validation failure into sentences a model can act on.
     *
     * The keys come back as `nodes.3.data.variable_key`, which names a position
     * in an array the model does not think in. It thinks in keys, so the index
     * is translated back into the node's own key and type.
     *
     * @return list<string>
     */
    private function describeNodeErrors(ValidationException $e, array $nodes): array
    {
        $problems = [];

        foreach ($e->errors() as $key => $messages) {
            $parts = explode('.', $key);
            $index = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : null;
            $node = $index !== null ? ($nodes[$index] ?? null) : null;
            $field = implode('.', array_slice($parts, 3)) ?: 'data';

            $where = $node
                ? "Node \"{$node['key']}\" ({$node['type']})"
                : 'The flow';

            foreach ((array) $messages as $message) {
                $problems[] = "{$where}, field \"{$field}\": {$message}";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function flatten(array $errors): array
    {
        return array_values(array_unique(array_merge(...array_values($errors))));
    }

    /** @return array{name: string, nodes: list, edges: list} */
    protected function exportOf(Flow $flow): array
    {
        return FlowGraph::export($flow);
    }
}
