<?php

namespace App\Services\Mcp\Tools;

use App\Models\Flow;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;

/**
 * What flows exist, without their contents.
 *
 * Summaries only, for the same reason the dashboard's own list endpoint selects
 * around `flow_nodes.data`: a workspace with twenty flows would otherwise pour
 * twenty complete automations into the model's context to answer "which one
 * handles the opening message".
 */
class ListFlowsTool extends Tool
{
    public function name(): string
    {
        return 'list_flows';
    }

    public function title(): string
    {
        return 'List flows';
    }

    public function description(): string
    {
        return 'List the automation flows in this workspace: id, name, how many steps each has, '
            .'and which connected channels run it. Returns no step contents — call get_flow for one flow.';
    }

    public function inputSchema(): array
    {
        return $this->noArguments();
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
        $flows = Flow::where('tenant_id', $user->tenant_id)
            ->withCount('nodes')
            ->with('connections:id,flow_id,name,channel')
            ->orderBy('name')
            ->get();

        $rows = $flows->map(fn (Flow $flow) => [
            'id' => $flow->id,
            'name' => $flow->name,
            'nodes' => $flow->nodes_count,
            // Which channels run this flow decides what it may contain — the
            // interactive node only exists on WhatsApp Official — so it belongs
            // in the summary rather than one call deeper.
            'used_by' => $flow->connections->map(fn ($connection) => [
                'connection' => $connection->name,
                'channel' => $connection->channel instanceof \BackedEnum
                    ? $connection->channel->value
                    : (string) $connection->channel,
            ])->values()->all(),
            // ⚠️ `last_updated_at` is cast to `timestamp`, so it arrives as an
            // integer rather than a Carbon instance — unlike every other date
            // on this platform.
            'updated_at' => $flow->last_updated_at
                ? \Illuminate\Support\Carbon::createFromTimestamp($flow->last_updated_at)->toIso8601String()
                : null,
        ])->values()->all();

        $summary = $rows === []
            ? 'This workspace has no flows yet.'
            : count($rows).' flow(s) in this workspace.';

        return ToolResult::data(['flows' => $rows], $summary);
    }
}
