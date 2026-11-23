<?php

namespace App\Services\Messaging;

use App\Models\Conversation;

/**
 * Whether a conversation's channel would refuse free-form content right now —
 * for messages the platform sends on its own initiative, long after the
 * customer last wrote: a campaign, an incident's paced notices, "it is fixed".
 *
 * Two sources, checked separately because they cover different channels:
 * MessagingWindow (WhatsApp Official, TikTok) also drives the live chat guard,
 * while Channel::broadcastWindowHours() (Instagram, Messenger) exists only for
 * sends like these — see its docblock for why those two cannot share one list.
 */
final class FreeFormWindow
{
    public static function closed(Conversation $conversation): bool
    {
        if (MessagingWindow::appliesTo($conversation)) {
            return ! MessagingWindow::isOpen($conversation);
        }

        $hours = $conversation->connection?->channel?->broadcastWindowHours();

        if ($hours === null) {
            return false;
        }

        $lastInbound = MessagingWindow::lastInboundAt($conversation);

        // Never written to us at all is closed too — there is no session to
        // reply inside of, same reasoning as MessagingWindow::isOpen().
        return $lastInbound === null || $lastInbound->copy()->addHours($hours)->isPast();
    }
}
