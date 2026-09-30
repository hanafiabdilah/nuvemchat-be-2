<?php

namespace App\Services\Mcp\Tools\Concerns;

use App\Models\User;
use App\Services\Mcp\Tools\ToolException;
use App\Services\Statistics\StatsScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The statistics filter set, read exactly the way the dashboard reads it.
 *
 * ⚠️ Built through StatsScope::fromRequest() on a synthetic request rather than
 * by calling the constructor: that method owns the validation (calendar days
 * only, never date *expressions*), the viewer's timezone, the 400-day ceiling
 * and the swap of a reversed range. A second reading of the same arguments
 * would be right on the day it was written and drift after the next rule.
 */
trait ReadsStatistics
{
    /** The filter properties every statistics tool accepts, as JSON Schema. */
    protected function filterProperties(): array
    {
        $ids = fn (string $what) => [
            'type' => 'array',
            'items' => ['type' => 'integer'],
            'description' => "Only these {$what} (ids from get_statistics_filters).",
        ];

        return [
            'from' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'First day, YYYY-MM-DD, in "timezone". Default: 29 days before "to".'],
            'to' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Last day, YYYY-MM-DD. Default: today. At most 400 days are covered.'],
            'timezone' => ['type' => 'string', 'description' => 'IANA timezone the days and hours are counted in, e.g. America/Sao_Paulo. Default: the server\'s.'],
            'scope' => ['type' => 'string', 'enum' => [StatsScope::SCOPE_CHAT, StatsScope::SCOPE_EMAIL, StatsScope::SCOPE_ALL], 'description' => 'Chat, e-mail or both. Chat and e-mail are answered on very different clocks — mixing them hides both. Default: all.'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these channels, e.g. whatsapp_official, instagram.'],
            'connection_ids' => $ids('connections'),
            'agent_ids' => $ids('agents'),
            'tag_ids' => $ids('tags'),
            'include_groups' => ['type' => 'boolean', 'description' => 'Include group conversations. Default false — groups are never assigned and drag every service metric.'],
        ];
    }

    protected function statsScope(array $arguments, User $user): StatsScope
    {
        $query = array_intersect_key($arguments, $this->filterProperties());

        try {
            return StatsScope::fromRequest(Request::create('/', 'GET', $query), (int) $user->tenant_id);
        } catch (ValidationException $e) {
            throw new ToolException(
                'Some filters are not valid.',
                array_values(array_unique(array_merge(...array_values($e->errors())))),
            );
        }
    }

    /** The window a section measured, echoed the way the dashboard echoes it. */
    protected function rangeOf(StatsScope $scope): array
    {
        return [
            'from' => $scope->from->toIso8601String(),
            'to' => $scope->to->toIso8601String(),
            'previous_from' => $scope->previousFrom->toIso8601String(),
            'previous_to' => $scope->previousTo->toIso8601String(),
            'timezone' => $scope->timezone,
            'scope' => $scope->scope,
        ];
    }
}
