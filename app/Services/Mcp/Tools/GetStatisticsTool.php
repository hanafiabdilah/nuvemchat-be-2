<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\ReadsStatistics;
use App\Services\Statistics\AutomationStats;
use App\Services\Statistics\HealthStats;
use App\Services\Statistics\OverviewStats;
use App\Services\Statistics\ServiceStats;
use App\Services\Statistics\TopicStats;
use App\Services\Statistics\VolumeStats;

/**
 * One section of the Statistics page, over any period.
 *
 * One tool with a `section` argument rather than six tools: they take the same
 * filters, describe the same slice of data, and a model asked "how did last
 * month go" should pick a section, not a tool out of six near-identical ones.
 * The agents section is its own tool only because it has its own permission.
 */
class GetStatisticsTool extends Tool
{
    use ReadsStatistics;

    private const SECTIONS = ['overview', 'volume', 'service', 'topics', 'automation', 'health'];

    public function name(): string
    {
        return 'get_statistics';
    }

    public function title(): string
    {
        return 'Read statistics';
    }

    public function description(): string
    {
        return 'Read one section of this workspace\'s Statistics page for a period, with the same period before it for '
            .'comparison. Sections: "overview" (headline numbers and trends, plus a right-now queue snapshot), '
            .'"volume" (conversations and messages per day, hour and channel), "service" (first-response and '
            .'resolution times as median/p90, response rate, SLA), "topics" (tags), "automation" (flows and AI: '
            .'share of replies automated, handoffs, AI cost) and "health" (failed sends, connection problems). '
            .'For per-agent numbers use get_agent_statistics. Durations are in seconds.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => ['type' => 'string', 'enum' => self::SECTIONS, 'description' => 'Which section to read.'],
                'sla_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440, 'description' => 'For "service": the first-response target the SLA share is measured against. Default 10.'],
                ...$this->filterProperties(),
            ],
            'required' => ['section'],
            'additionalProperties' => false,
        ];
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
        $section = (string) ($arguments['section'] ?? '');

        if (! in_array($section, self::SECTIONS, true)) {
            throw new ToolException('"section" must be one of: '.implode(', ', self::SECTIONS).'.');
        }

        $scope = $this->statsScope($arguments, $user);
        $sla = max(1, min((int) ($arguments['sla_minutes'] ?? 10), 1440));

        $data = match ($section) {
            'overview' => (new OverviewStats($scope))->build(),
            'volume' => (new VolumeStats($scope))->build(),
            'service' => (new ServiceStats($scope, $sla))->build(),
            'topics' => (new TopicStats($scope))->build(),
            'automation' => (new AutomationStats($scope))->build(),
            'health' => (new HealthStats($scope))->build(),
        };

        return ToolResult::data(
            ['section' => $section, 'range' => $this->rangeOf($scope)] + $data,
            "Statistics \"{$section}\" from {$scope->from->copy()->setTimezone($scope->timezone)->toDateString()} "
                ."to {$scope->to->copy()->setTimezone($scope->timezone)->toDateString()} ({$scope->timezone}).",
        );
    }
}
