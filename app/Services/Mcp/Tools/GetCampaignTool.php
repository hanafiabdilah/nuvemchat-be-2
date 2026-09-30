<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Enums\Broadcast\RecipientStatus;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithCampaigns;

/**
 * One campaign, plus the reasons its failures failed.
 *
 * The failure breakdown is here rather than one call deeper because it is
 * almost always the question: "why did 300 of these fail?" is answered by
 * three distinct reasons, not by paging through 300 rows.
 */
class GetCampaignTool extends Tool
{
    use WorksWithCampaigns;

    public function name(): string
    {
        return 'get_campaign';
    }

    public function title(): string
    {
        return 'Read a campaign';
    }

    public function description(): string
    {
        return 'Read one campaign: its status, content, delivery numbers and the most common reasons recipients failed '
            .'or were skipped. Use list_campaign_recipients to see individual recipients.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'campaign_id' => ['type' => 'integer', 'description' => 'The campaign id, from list_campaigns.'],
            ],
            'required' => ['campaign_id'],
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
        $campaign = $this->findCampaign($arguments['campaign_id'] ?? 0, $user);

        $reasons = fn (RecipientStatus $status) => $campaign->recipients()
            ->where('status', $status)
            ->whereNotNull('error')
            ->selectRaw('error, count(*) as count')
            ->groupBy('error')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['reason' => $row->error, 'count' => (int) $row->count])
            ->values()
            ->all();

        $data = $this->describeCampaign($campaign) + [
            'failure_reasons' => $reasons(RecipientStatus::Failed),
            'skip_reasons' => $reasons(RecipientStatus::Skipped),
        ];

        return ToolResult::data(
            $data,
            "Campaign \"{$campaign->name}\" (#{$campaign->id}) is {$campaign->status->value}.",
        );
    }
}
