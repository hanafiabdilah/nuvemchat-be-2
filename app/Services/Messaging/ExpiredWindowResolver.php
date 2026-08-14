<?php

namespace App\Services\Messaging;

use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Services\Conversation\SystemMessage;

/**
 * Turns an expired session window into the end of a conversation: an info note
 * in the thread, then status Resolved.
 *
 * Deliberately silent on the channel. The whole point is that nothing can reach
 * the customer any more, so the connection's closing message is skipped — it
 * would be the very message that fails.
 */
class ExpiredWindowResolver
{
    /** Matched by the SPA to render the note in the reader's language. */
    public const CODE = 'messaging_window_expired';

    /**
     * @return bool Whether this call is what closed the conversation.
     */
    public static function resolve(Conversation $conversation): bool
    {
        // Only a live conversation is closed this way. Pending / AI-handling
        // threads are the flow engine's business, and a resolved one is done.
        if ($conversation->status !== Status::Active) {
            return false;
        }

        if (! MessagingWindow::hasExpired($conversation)) {
            return false;
        }

        $hours = MessagingWindow::hoursFor($conversation->connection->channel);

        SystemMessage::info(
            $conversation,
            "The {$hours}-hour reply window closed, so this conversation was resolved automatically.",
            self::CODE,
            ['hours' => $hours],
        );

        // After the note, so the broadcast carries the last_message_at the note
        // just bumped and every inbox row lands on its final state at once.
        // No resolver id: nobody closed this, the window did.
        $conversation->markResolved();

        broadcast(new ConversationUpdated($conversation));

        return true;
    }
}
