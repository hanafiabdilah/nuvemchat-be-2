<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when Meta reports a WhatsApp message template's review status has
 * changed (`message_template_status_update` webhook). Templates aren't stored
 * locally, so this just nudges the dashboard to refresh / notify the agent.
 */
class TemplateStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int|string $tenantId,
        public array $value,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('tenant-channel.' . $this->tenantId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'template-status-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'name' => $this->value['message_template_name'] ?? null,
            'language' => $this->value['message_template_language'] ?? null,
            'status' => $this->value['event'] ?? null,
            'reason' => $this->value['reason'] ?? null,
            'template_id' => $this->value['message_template_id'] ?? null,
        ];
    }
}
