<?php

namespace App\Services\Mcp\Tools;

use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowGraph;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithFlows;

/**
 * Replace a flow's whole graph.
 *
 * ⚠️ Lenient where create_flow is strict, and the asymmetry is the point. A
 * flow being edited may have carried a dead branch or an unreachable step since
 * before anyone here was involved; refusing to save because of a problem the
 * caller did not create would mean older flows simply cannot be touched from an
 * editor. So it saves, and reports what is wrong as warnings — the model sees
 * them and can offer to fix them, which is better than either silence or a
 * refusal.
 *
 * ⚠️ Whole-graph. A step left out of the call is deleted along with its edges.
 * That is not a quirk of this tool — it is what the builder's own save does —
 * and it is why both this description and the server's instructions tell a
 * model to read, change and send everything back.
 */
class UpdateFlowTool extends Tool
{
    use WorksWithFlows;

    public function name(): string
    {
        return 'update_flow';
    }

    public function title(): string
    {
        return 'Change a flow';
    }

    public function description(): string
    {
        return 'Replace a flow\'s steps and connections with the graph you send. Read it with get_flow first, '
            .'change what you need, and send ALL of it back: any step you leave out is deleted. '
            .'Keep each step\'s "key" exactly as get_flow returned it — a changed key replaces the step, which '
            .'drops any conversation currently waiting at it. Returns warnings for problems the flow already had.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'flow_id' => ['type' => 'integer', 'description' => 'The flow id, from list_flows or get_flow.'],
                'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Optional new name. Left alone if omitted.'],
                'nodes' => [
                    'type' => 'array',
                    'description' => 'Every step the flow should have afterwards, in the shape get_flow returns.',
                    'items' => ['type' => 'object'],
                ],
                'edges' => [
                    'type' => 'array',
                    'description' => 'Every connection the flow should have afterwards.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['flow_id', 'nodes'],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::FLOWS_WRITE;
    }

    public function permission(): string
    {
        return 'flows.update';
    }

    public function annotations(): array
    {
        // Destructive: it can delete steps, and a flow is live on customer
        // conversations. Clients use this to decide what to confirm.
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $flow = $this->findFlow($arguments['flow_id'] ?? 0, $user);

        $graph = $this->prepareGraph($arguments);

        $this->assertNoSelfJump($graph['nodes'], $flow->id);

        $shape = FlowGraph::fromExportShape($graph['nodes'], $graph['edges']);

        $idMap = FlowGraph::replace($flow, $shape['nodes'], $shape['edges']);

        if (($name = trim((string) ($arguments['name'] ?? ''))) !== '') {
            $flow->name = $name;
        }

        $flow->last_updated_at = now();
        $flow->save();

        AuditLog::record(
            'mcp.flow.updated',
            "Changed flow \"{$flow->name}\" (#{$flow->id}) from {$connection->client_name}",
            ['tenant_id' => $user->tenant_id, 'flow_id' => $flow->id, 'mcp_connection_id' => $connection->id],
            $user,
        );

        $warnings = FlowBlueprint::structureProblems($graph['nodes'], $graph['edges']);

        foreach ($graph['dropped_edges'] as $edge) {
            $warnings[] = sprintf(
                'A second edge leaving "%s" was dropped: an output leads to exactly one step.',
                $edge['source_key'] ?? '?',
            );
        }

        $summary = "Saved flow \"{$flow->name}\" (#{$flow->id}).";

        if ($warnings !== []) {
            $summary .= ' It saved, but '.count($warnings).' thing(s) in it will not behave as drawn.';
        }

        return ToolResult::data([
            'flow_id' => $flow->id,
            'name' => $flow->name,
            'nodes' => count($graph['nodes']),
            // The caller's keys mapped to the stored ids. A new step gets a
            // fresh id here; sending the old key again next time would create
            // it a second time and delete this one.
            'node_keys' => $idMap,
            'warnings' => array_values($warnings),
        ], $summary);
    }
}
