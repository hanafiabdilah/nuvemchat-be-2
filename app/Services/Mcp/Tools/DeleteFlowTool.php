<?php

namespace App\Services\Mcp\Tools;

use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithFlows;

/**
 * Delete a flow.
 *
 * ⚠️ Refuses while a connection still runs it. Deleting the row would not stop
 * anything gracefully — `connections.flow_id` is nullOnDelete, so the channel
 * simply loses its automation with no trace of why, and every conversation
 * parked mid-flow loses the node it was standing on. Detaching it is a decision
 * about a live channel, and it belongs to the person in the dashboard, not to a
 * tool call.
 */
class DeleteFlowTool extends Tool
{
    use WorksWithFlows;

    public function name(): string
    {
        return 'delete_flow';
    }

    public function title(): string
    {
        return 'Delete a flow';
    }

    public function description(): string
    {
        return 'Delete a flow permanently, with all its steps. There is no undo. '
            .'Refused while any connection still runs the flow — detach it in Pingly first.';
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
        return Scopes::FLOWS_WRITE;
    }

    public function permission(): string
    {
        return 'flows.delete';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $flow = $this->findFlow($arguments['flow_id'] ?? 0, $user);

        $inUse = $flow->connections()->pluck('name');

        if ($inUse->isNotEmpty()) {
            throw new ToolException(sprintf(
                'Flow "%s" still runs on %s. Detach it from those connections in Pingly first, then delete it.',
                $flow->name,
                $inUse->implode(', '),
            ));
        }

        $name = $flow->name;
        $id = $flow->id;

        $flow->delete();

        AuditLog::record(
            'mcp.flow.deleted',
            "Deleted flow \"{$name}\" (#{$id}) from {$connection->client_name}",
            ['tenant_id' => $user->tenant_id, 'flow_id' => $id, 'mcp_connection_id' => $connection->id],
            $user,
        );

        return ToolResult::data(['deleted' => true, 'flow_id' => $id], "Deleted flow \"{$name}\".");
    }
}
