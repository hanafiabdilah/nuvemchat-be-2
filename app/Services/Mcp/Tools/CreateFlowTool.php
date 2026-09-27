<?php

namespace App\Services\Mcp\Tools;

use App\Models\AuditLog;
use App\Models\Flow;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowGraph;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithFlows;
use Illuminate\Support\Facades\DB;

/**
 * Create a flow from a complete graph.
 *
 * ⚠️ Held to the strict standard — the same `structureProblems` the in-app
 * assistant must satisfy, not the lenient one the builder's auto-save uses.
 * The builder is lenient because a person is mid-edit and a half-drawn node
 * must still save; nothing here is mid-edit, and every problem in a brand-new
 * flow is one this call just introduced. `update_flow` is the other way round
 * and says why.
 */
class CreateFlowTool extends Tool
{
    use WorksWithFlows;

    public function name(): string
    {
        return 'create_flow';
    }

    public function title(): string
    {
        return 'Create a flow';
    }

    public function description(): string
    {
        return 'Create a new automation flow. Call get_flow_specification first — the graph must use this '
            .'workspace\'s real ids and the exact node format. The flow is checked in full before anything '
            .'is written: exactly one start step, every step reachable, every branch value one the step can '
            .'actually produce. It is created switched off until you attach it to a connection in Pingly.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'What this flow is called.'],
                'nodes' => [
                    'type' => 'array',
                    'description' => 'The steps. Each needs key, type and data; position_x/position_y are optional and will be laid out for you.',
                    'items' => ['type' => 'object'],
                ],
                'edges' => [
                    'type' => 'array',
                    'description' => 'The connections: source_key, target_key and condition_value for a branch.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['name', 'nodes'],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::FLOWS_WRITE;
    }

    public function permission(): string
    {
        return 'flows.create';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ($name === '') {
            throw new ToolException('The flow needs a name.');
        }

        $graph = $this->prepareGraph($arguments);

        $problems = FlowBlueprint::structureProblems($graph['nodes'], $graph['edges']);

        if ($problems !== []) {
            throw new ToolException('This flow would not work as drawn, so nothing was created.', $problems);
        }

        $shape = FlowGraph::fromExportShape($graph['nodes'], $graph['edges']);

        $flow = DB::transaction(function () use ($user, $name, $shape) {
            $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => $name]);

            // Created empty, then filled: `replace()` needs a flow to own the
            // rows, and a flow's own auto-created start node would otherwise be
            // a second one beside the caller's.
            $flow->nodes()->delete();

            FlowGraph::replace($flow, $shape['nodes'], $shape['edges']);

            return $flow->fresh();
        });

        AuditLog::record(
            'mcp.flow.created',
            "Created flow \"{$flow->name}\" (#{$flow->id}) from {$connection->client_name}",
            ['tenant_id' => $user->tenant_id, 'flow_id' => $flow->id, 'mcp_connection_id' => $connection->id],
            $user,
        );

        return ToolResult::data(
            ['flow_id' => $flow->id, 'name' => $flow->name, 'nodes' => count($graph['nodes'])],
            "Created flow \"{$flow->name}\" (#{$flow->id}). It is not attached to any connection yet, so it is not running.",
        );
    }
}
