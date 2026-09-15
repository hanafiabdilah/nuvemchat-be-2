<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\Lead\LeadAttendance;
use Illuminate\Support\Facades\Log;

/**
 * Tells the funnel when someone from the team answers a contact.
 *
 * Hooked to the model rather than to each send route: `sent_by_user_id` is
 * written by a dozen paths (text, media, templates, accept and closing
 * messages, inbox sends) and whatever adds the next one, and a hook per route
 * would be complete on the day it was written.
 */
class MessageLeadObserver
{
    public function __construct(
        private LeadAttendance $attendance,
    ) {}

    public function saved(Message $message): void
    {
        if ($message->sent_by_user_id === null) {
            return;
        }

        // Send paths create the row first and stamp the author right after.
        if (! $message->wasRecentlyCreated && ! $message->wasChanged('sent_by_user_id')) {
            return;
        }

        // The message is already on the customer's phone; a funnel hiccup must
        // never turn that into a failed send.
        try {
            $this->attendance->noteHumanReply($message);
        } catch (\Throwable $e) {
            Log::warning('Could not move the lead after an agent reply', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
