<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Enums\Broadcast\RecipientStatus;
use App\Models\BroadcastRecipient;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithCampaigns;

/** The delivery report of one campaign, one row per person. */
class ListCampaignRecipientsTool extends Tool
{
    use WorksWithCampaigns;

    public function name(): string
    {
        return 'list_campaign_recipients';
    }

    public function title(): string
    {
        return 'List campaign recipients';
    }

    public function description(): string
    {
        return 'The delivery report of one campaign: each recipient\'s address, name, status (pending, sending, sent, '
            .'failed, skipped), the reason when it failed or was skipped, and the conversation it opened. '
            .'Filter by status — "failed" is usually the page that matters.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'campaign_id' => ['type' => 'integer', 'description' => 'The campaign id, from list_campaigns.'],
                'status' => ['type' => 'string', 'enum' => array_column(RecipientStatus::cases(), 'value'), 'description' => 'Only recipients in this state.'],
                'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Only recipients whose address or name contains this.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'How many to return. Default 50.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page of results, starting at 1.'],
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

        $status = $arguments['status'] ?? null;

        if ($status !== null && RecipientStatus::tryFrom((string) $status) === null) {
            throw new ToolException('"status" must be one of: '.implode(', ', array_column(RecipientStatus::cases(), 'value')).'.');
        }

        $search = trim((string) ($arguments['search'] ?? ''));
        $limit = min(200, max(1, (int) ($arguments['limit'] ?? 50)));
        $page = max(1, (int) ($arguments['page'] ?? 1));

        $recipients = $campaign->recipients()
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($inner) => $inner->where('address', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('id')
            ->paginate($limit, ['*'], 'page', $page);

        return ToolResult::data(
            [
                'campaign_id' => $campaign->id,
                'recipients' => collect($recipients->items())->map(fn (BroadcastRecipient $r) => [
                    'address' => $r->address,
                    'name' => $r->displayName(),
                    'status' => $r->status->value,
                    'error' => $r->error,
                    'attempts' => (int) $r->attempts,
                    'sent_at' => $r->sent_at?->toIso8601String(),
                    'conversation_id' => $r->conversation_id,
                ])->values()->all(),
                'page' => $recipients->currentPage(),
                'has_more' => $recipients->hasMorePages(),
                'total' => $recipients->total(),
            ],
            $recipients->total()." recipient(s) of \"{$campaign->name}\"; showing page {$recipients->currentPage()}.",
        );
    }
}
