<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\TrainedAgentHire;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired on any trained-agent hire state change (paid, forked, failed,
 * abandoned) so the AI Agents page can refresh and the Pix modal can close
 * itself once the charge settles.
 */
class TrainedAgentHireUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public TrainedAgentHire $hire
    ) {
        //
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            Channels::tenant($this->hire->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'trained-agent-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->hire->id,
            'status' => $this->hire->status->value,
            'source' => $this->hire->source->value,
            'blueprint_id' => $this->hire->trained_agent_blueprint_id,
            'ai_hub_agent_id' => $this->hire->ai_hub_agent_id,
        ];
    }
}
