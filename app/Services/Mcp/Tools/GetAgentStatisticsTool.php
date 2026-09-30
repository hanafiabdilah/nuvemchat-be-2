<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\ReadsStatistics;
use App\Services\Statistics\AgentStats;

/**
 * The Agents tab of Statistics. Its own tool because it is its own permission
 * in the dashboard (`statistics.agents.view`): numbers about named colleagues
 * are a different grant from numbers about the workspace.
 */
class GetAgentStatisticsTool extends Tool
{
    use ReadsStatistics;

    public function name(): string
    {
        return 'get_agent_statistics';
    }

    public function title(): string
    {
        return 'Read agent statistics';
    }

    public function description(): string
    {
        return 'Per-agent numbers for a period: conversations handled and resolved, messages sent, first-response and '
            .'resolution times (seconds). Takes the same filters as get_statistics.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->filterProperties(),
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::STATISTICS_READ;
    }

    public function permission(): string
    {
        return 'statistics.agents.view';
    }

    public function feature(): Feature
    {
        return Feature::Statistics;
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $scope = $this->statsScope($arguments, $user);

        return ToolResult::data(
            ['range' => $this->rangeOf($scope)] + (new AgentStats($scope))->build(),
            'Agent statistics for the period.',
        );
    }
}
