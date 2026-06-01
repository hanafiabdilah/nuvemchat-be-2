<?php

namespace App\Events\Widget;

use App\Enums\Conversation\Status;
use App\Models\Conversation;
use App\Models\LiveChatSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetConversationStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Status $oldStatus,
        public Status $newStatus,
    ) {
        //
    }

    public function broadcastOn(): array
    {
        $session = LiveChatSession::where('conversation_id', $this->conversation->id)->first();

        if (!$session) {
            return [];
        }

        return [
            new Channel('widget-session.' . $session->session_token),
        ];
    }

    public function broadcastAs(): string
    {
        return 'widget-conversation-status-changed';
    }

    public function broadcastWith(): array
    {
        $this->conversation->loadMissing('agent:id,name');

        return [
            'conversation_id' => $this->conversation->id,
            'old_status' => $this->oldStatus->value,
            'new_status' => $this->newStatus->value,
            'agent' => $this->conversation->agent ? [
                'id' => $this->conversation->agent->id,
                'name' => $this->conversation->agent->name,
            ] : null,
            'changed_at' => now()->toIso8601String(),
        ];
    }
}
