<?php

namespace App\Services\Mcp\Tools;

use App\Models\Broadcast;

class CancelCampaignTool extends CampaignActionTool
{
    public function name(): string
    {
        return 'cancel_campaign';
    }

    public function title(): string
    {
        return 'Cancel a campaign';
    }

    public function description(): string
    {
        return 'Cancel a campaign for good: everyone not yet reached is marked skipped and it can never be resumed. '
            .'Messages already sent stay sent. To stop temporarily, use pause_campaign instead.';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    protected function verb(): string
    {
        return 'canceled';
    }

    protected function apply(Broadcast $campaign): Broadcast
    {
        return $this->broadcasts->cancel($campaign);
    }
}
