<?php

namespace App\Services\Mcp\Tools;

use App\Models\Broadcast;

class ResumeCampaignTool extends CampaignActionTool
{
    public function name(): string
    {
        return 'resume_campaign';
    }

    public function title(): string
    {
        return 'Resume a campaign';
    }

    public function description(): string
    {
        return 'Resume a paused campaign: sending continues to the recipients still pending, at the campaign\'s rate. '
            .'This sends messages to customers — confirm with the person first.';
    }

    protected function verb(): string
    {
        return 'resumed';
    }

    protected function apply(Broadcast $campaign): Broadcast
    {
        return $this->broadcasts->resume($campaign);
    }
}
