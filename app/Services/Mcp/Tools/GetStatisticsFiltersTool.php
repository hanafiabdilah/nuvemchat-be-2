<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use Illuminate\Support\Facades\DB;

/**
 * The ids the statistics filters take: connections, channels, tags and agents.
 * The same list the dashboard's filter bar is built from.
 */
class GetStatisticsFiltersTool extends Tool
{
    public function name(): string
    {
        return 'get_statistics_filters';
    }

    public function title(): string
    {
        return 'List statistics filters';
    }

    public function description(): string
    {
        return 'The connections, channels, tags and agents of this workspace, with the ids get_statistics and '
            .'get_agent_statistics accept as filters. Call it when the person names a number, a tag or a colleague.';
    }

    public function inputSchema(): array
    {
        return $this->noArguments();
    }

    public function scope(): string
    {
        return Scopes::STATISTICS_READ;
    }

    public function permission(): string
    {
        return 'statistics.tenant.view';
    }

    public function feature(): Feature
    {
        return Feature::Statistics;
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $tenantId = $user->tenant_id;

        $connections = DB::table('connections')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'channel', 'status']);

        return ToolResult::data([
            'connections' => $connections->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'channel' => $row->channel,
                'status' => $row->status,
            ])->all(),
            'channels' => $connections->pluck('channel')->unique()->values()->all(),
            'tags' => DB::table('tags')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])->all(),
            'agents' => DB::table('users')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])->all(),
        ], 'Filter options for statistics.');
    }
}
