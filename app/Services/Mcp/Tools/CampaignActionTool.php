<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Broadcast\BroadcastService;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithCampaigns;

/**
 * Operating a campaign somebody already decided to send.
 *
 * ⚠️ Every action goes through BroadcastService — the same state machine the
 * dashboard buttons use — so a campaign paused from an editor is one the
 * dashboard understands and can resume, and the service's own refusals ("Only
 * a running campaign can be paused.") reach the model verbatim.
 *
 * Gated by `broadcasts.send`, like the dashboard's own start/pause/resume/
 * cancel routes: stopping a campaign and restarting one are the same level of
 * trust, and splitting them here would invent a permission the workspace
 * cannot grant.
 */
abstract class CampaignActionTool extends Tool
{
    use WorksWithCampaigns;

    public function __construct(
        protected readonly BroadcastService $broadcasts,
    ) {}

    /** The audit action suffix and the verb in the summary: `paused`, `canceled`, … */
    abstract protected function verb(): string;

    abstract protected function apply(Broadcast $campaign): Broadcast;

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
        return Scopes::CAMPAIGNS_WRITE;
    }

    public function permission(): string
    {
        return 'broadcasts.send';
    }

    public function feature(): Feature
    {
        return Feature::Mcp;
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $campaign = $this->findCampaign($arguments['campaign_id'] ?? 0, $user);
        $before = $campaign->status->value;

        $this->transition(fn () => $this->apply($campaign));

        $campaign = $campaign->fresh(['connection', 'creator']);

        AuditLog::record(
            'mcp.campaign.'.$this->verb(),
            ucfirst($this->verb())." campaign \"{$campaign->name}\" (#{$campaign->id}) from {$connection->client_name}",
            [
                'tenant_id' => $user->tenant_id,
                'broadcast_id' => $campaign->id,
                'from_status' => $before,
                'to_status' => $campaign->status->value,
                'mcp_connection_id' => $connection->id,
            ],
            $user,
        );

        return ToolResult::data(
            $this->describeCampaign($campaign),
            "Campaign \"{$campaign->name}\" is now {$campaign->status->value}.",
        );
    }
}
