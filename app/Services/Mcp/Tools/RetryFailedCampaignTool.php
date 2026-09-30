<?php

namespace App\Services\Mcp\Tools;

use App\Models\Broadcast;

class RetryFailedCampaignTool extends CampaignActionTool
{
    public function name(): string
    {
        return 'retry_failed_campaign';
    }

    public function title(): string
    {
        return 'Retry failed recipients';
    }

    public function description(): string
    {
        return 'Put a campaign\'s failed recipients back in the queue and start sending to them again. Skipped recipients '
            .'(opted out, window closed) are left alone. Check get_campaign\'s failure_reasons first — retrying a failure '
            .'whose cause has not changed only fails again. This sends messages to customers — confirm with the person first.';
    }

    protected function verb(): string
    {
        return 'retried';
    }

    protected function apply(Broadcast $campaign): Broadcast
    {
        return $this->broadcasts->retryFailed($campaign);
    }
}
