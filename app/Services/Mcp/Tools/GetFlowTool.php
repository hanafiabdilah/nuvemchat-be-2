<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithFlows;

/**
 * One flow's whole graph, in the shape update_flow accepts.
 *
 * ⚠️ The same shape on the way out as on the way in, deliberately. Editing here
 * is read-change-write: a model that has to translate between two spellings of
 * a node will get it wrong eventually, and the failure would be a flow saved
 * with the wrong things in it rather than an error.
 */
class GetFlowTool extends Tool
{
    use WorksWithFlows;

    public function name(): string
    {
        return 'get_flow';
    }

    public function title(): string
    {
        return 'Read a flow';
    }

    public function description(): string
    {
        return 'Read one flow in full: every step, its settings and the edges between them. '
            .'The result is exactly the shape update_flow expects, so edit what comes back and send all of it. '
            .'Keep each node\'s "key" unchanged for steps you are not replacing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'flow_id' => ['type' => 'integer', 'description' => 'The flow id, from list_flows.'],
            ],
            'required' => ['flow_id'],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::FLOWS_READ;
    }

    public function permission(): string
    {
        return 'flows.view';
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $flow = $this->findFlow($arguments['flow_id'] ?? 0, $user);

        $graph = $this->exportOf($flow);

        return ToolResult::data(
            ['flow_id' => $flow->id] + $graph,
            "Flow \"{$flow->name}\" (#{$flow->id}) with ".count($graph['nodes']).' step(s).',
        );
    }
}
