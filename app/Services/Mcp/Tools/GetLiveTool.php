<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Live\LiveMonitor;
use App\Services\Mcp\Scopes;

/**
 * The live monitor: what is happening in the workspace right now.
 *
 * ⚠️ Metadata only, the same as the page — LiveMonitor never returns a single
 * character of message content, and that is what makes it safe to hand to a
 * model sitting on somebody's laptop. Scoped through LiveMonitor::forUser(),
 * so an agent holding one inbox sees that inbox and nothing else, exactly as
 * in the dashboard.
 */
class GetLiveTool extends Tool
{
    public function name(): string
    {
        return 'get_live';
    }

    public function title(): string
    {
        return 'Read the live monitor';
    }

    public function description(): string
    {
        return 'What is happening in this workspace right now (the last '.LiveMonitor::WINDOW_MINUTES.' minutes): '
            .'the queue (conversations waiting for a person, with AI, needing a human), messages per minute in and out, '
            .'recent activity (transfers, take-overs, resolutions, handoffs to a human) and — when the person may see '
            .'agent data — who is online and how many conversations each is holding. Recent message events carry who '
            .'sent them, the channel and the contact, never the text. To follow along, call again with "after_id" set to '
            .'the "cursor" from the previous result to get only newer events.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => LiveMonitor::scopes(), 'description' => 'Chat, e-mail or both. Default: all.'],
                'after_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Only message events newer than this cursor (from a previous call).'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => LiveMonitor::MAX_FEED_LIMIT, 'description' => 'How many message events at most. Default 30.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::LIVE_READ;
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
        $scope = (string) ($arguments['scope'] ?? LiveMonitor::SCOPE_ALL);

        if (! in_array($scope, LiveMonitor::scopes(), true)) {
            throw new ToolException('"scope" must be one of: '.implode(', ', LiveMonitor::scopes()).'.');
        }

        $afterId = isset($arguments['after_id']) ? max(0, (int) $arguments['after_id']) : null;
        $limit = max(1, min((int) ($arguments['limit'] ?? 30), LiveMonitor::MAX_FEED_LIMIT));

        $monitor = LiveMonitor::forUser($user, $scope);
        $events = $monitor->feed($afterId, $limit);

        $data = [
            'now' => now()->toIso8601String(),
            'window_minutes' => LiveMonitor::WINDOW_MINUTES,
            'pulse' => $monitor->pulse(),
            'activity' => $monitor->activity(),
            // Same rule as the page: the roster is agent data, behind the
            // permission of the Agents tab. Absent rather than empty, so the
            // model does not read "nobody is online".
            'agents' => $user->can('statistics.agents.view') ? $monitor->agents() : null,
            'events' => $events,
            // Never move the cursor backwards on an empty delta.
            'cursor' => $events === [] && $afterId !== null ? $afterId : $monitor->cursorFor($events),
        ];

        if ($data['agents'] === null) {
            unset($data['agents']);
        }

        return ToolResult::data($data, 'Live monitor, last '.LiveMonitor::WINDOW_MINUTES.' minutes.');
    }
}
