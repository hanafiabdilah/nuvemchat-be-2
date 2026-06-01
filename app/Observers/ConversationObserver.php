<?php

namespace App\Observers;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Events\Widget\WidgetConversationStatusChanged;
use App\Models\Conversation;
use App\Services\Flow\FlowExecutor;
use Illuminate\Support\Facades\Log;

class ConversationObserver
{
    /**
     * Handle the Conversation "updated" event.
     * Stop flow when conversation status changes from Pending to Active/Resolved (admin handover).
     * Also broadcasts a widget event so embedded SDKs can react to status changes.
     */
    public function updated(Conversation $conversation): void
    {
        // Check if status was changed
        if (!$conversation->wasChanged('status')) {
            return;
        }

        // Get the previous status
        $oldStatus = $conversation->getOriginal('status');
        $newStatus = $conversation->status;

        // If status changed from Pending to Active or Resolved, stop the flow
        if ($oldStatus === Status::Pending && in_array($newStatus, [Status::Active, Status::Resolved])) {
            Log::info('ConversationObserver: Conversation status changed, stopping flow', [
                'conversation_id' => $conversation->id,
                'old_status' => $oldStatus->value,
                'new_status' => $newStatus->value,
            ]);

            $flowExecutor = new FlowExecutor();
            $flowExecutor->stopFlow($conversation);
        }

        // Notify the embedded widget SDK when this conversation belongs to a Live Chat Widget channel.
        if ($conversation->connection?->channel === Channel::LiveChatWidget) {
            broadcast(new WidgetConversationStatusChanged($conversation, $oldStatus, $newStatus));
        }
    }
}
