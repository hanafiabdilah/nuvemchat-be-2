<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Enums\Broadcast\Status;
use App\Models\Broadcast;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithCampaigns;

/** The workspace's campaigns, newest first, with their delivery numbers. */
class ListCampaignsTool extends Tool
{
    use WorksWithCampaigns;

    public function name(): string
    {
        return 'list_campaigns';
    }

    public function title(): string
    {
        return 'List campaigns';
    }

    public function description(): string
    {
        return 'List the broadcast campaigns in this workspace, newest first: status, the connection they send from, '
            .'what they send and how many recipients were sent, failed, skipped or are still pending. '
            .'Call get_campaign for one campaign and list_campaign_recipients for who received it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => array_column(Status::cases(), 'value'), 'description' => 'Only campaigns in this state.'],
                'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Only campaigns whose name contains this.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'How many to return. Default 20.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page of results, starting at 1.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::CAMPAIGNS_READ;
    }

    public function permission(): string
    {
        return 'broadcasts.view';
    }

    public function feature(): Feature
    {
        return Feature::Mcp;
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $status = $arguments['status'] ?? null;

        if ($status !== null && Status::tryFrom((string) $status) === null) {
            throw new ToolException('"status" must be one of: '.implode(', ', array_column(Status::cases(), 'value')).'.');
        }

        $search = trim((string) ($arguments['search'] ?? ''));
        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 20)));
        $page = max(1, (int) ($arguments['page'] ?? 1));

        $campaigns = Broadcast::with(['connection', 'creator'])
            ->where('tenant_id', $user->tenant_id)
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return ToolResult::data(
            [
                'campaigns' => collect($campaigns->items())->map(fn (Broadcast $c) => $this->describeCampaign($c))->values()->all(),
                'page' => $campaigns->currentPage(),
                'has_more' => $campaigns->hasMorePages(),
                'total' => $campaigns->total(),
            ],
            $campaigns->total().' campaign(s); showing page '.$campaigns->currentPage().'.',
        );
    }
}
