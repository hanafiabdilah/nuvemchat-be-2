<?php

namespace App\Services\Mcp\Tools;

use App\Models\Broadcast;

class PauseCampaignTool extends CampaignActionTool
{
    public function name(): string
    {
        return 'pause_campaign';
    }

    public function title(): string
    {
        return 'Pause a campaign';
    }

    public function description(): string
    {
        return 'Pause a running campaign. Up to one batch already in flight (at most ~25 messages) may still go out. '
            .'Resume it later with resume_campaign; nothing is lost.';
    }

    protected function verb(): string
    {
        return 'paused';
    }

    protected function apply(Broadcast $campaign): Broadcast
    {
        return $this->broadcasts->pause($campaign);
    }
}
