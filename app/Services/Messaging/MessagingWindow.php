<?php

namespace App\Services\Messaging;

use App\Enums\Connection\Channel;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\TikTok\TikTokReplyWindow;
use App\Services\Conversation\CallLog;
use Carbon\Carbon;

/**
 * The session ("customer service") window: how long after the customer's last
 * message the business may still send free-form content.
 *
 * WhatsApp Official is why this exists. The Cloud API frequently ACCEPTS a late
 * message with HTTP 200 and only rejects it afterwards, as a `failed` status
 * webhook carrying error 131047 — by then the Message row is already written,
 * so the thread shows a message the customer never received. Asking before
 * sending is the only way to tell the agent the truth.
 *
 * Note what does NOT open the window: an outgoing message of any kind,
 * including an approved template. Only an inbound message does.
 */
class MessagingWindow
{
    /**
     * Hours of free-form replies a channel grants after each inbound message,
     * or null for channels that impose no window at all.
     */
    public static function hoursFor(Channel $channel): ?int
    {
        return match ($channel) {
            Channel::WhatsappOfficial => 24,
            // One number, one owner: the deeper TikTok guard already declares it.
            Channel::TikTok => TikTokReplyWindow::HOURS,
            default => null,
        };
    }

    public static function appliesTo(Conversation $conversation): bool
    {
        $channel = $conversation->connection?->channel;

        return $channel !== null && self::hoursFor($channel) !== null;
    }

    /**
     * True when free-form content may be sent right now. Channels without a
     * window are always open; a windowed conversation that has never received
     * an inbound message is not — there is no session to reply inside of.
     */
    public static function isOpen(Conversation $conversation): bool
    {
        if (! self::appliesTo($conversation)) {
            return true;
        }

        return self::closesAt($conversation)?->isFuture() ?? false;
    }

    /**
     * True when a window opened and has since run out.
     *
     * Deliberately narrower than `! isOpen()`: a conversation still waiting for
     * its first inbound message (one started by a template, say) is closed but
     * not expired, and closing it would throw away a thread whose reply is
     * still coming.
     */
    public static function hasExpired(Conversation $conversation): bool
    {
        if (! self::appliesTo($conversation)) {
            return false;
        }

        $closesAt = self::closesAt($conversation);

        return $closesAt !== null && $closesAt->isPast();
    }

    /**
     * The moment the window shuts, or null when the channel has none or the
     * customer has never written.
     */
    public static function closesAt(Conversation $conversation): ?Carbon
    {
        $channel = $conversation->connection?->channel;
        $hours = $channel ? self::hoursFor($channel) : null;

        if ($hours === null) {
            return null;
        }

        // copy() so this never shifts the Carbon instance it was handed.
        return self::lastInboundAt($conversation)?->copy()->addHours($hours);
    }

    /**
     * When the customer last reached out: wrote anything, or called.
     *
     * A call counts because Meta counts it — a customer calling the business,
     * answered or not, opens the same customer service window a message does.
     * Leaving calls out made the platform refuse what WhatsApp accepts: the
     * recording notice of the first recorded call was never sent to a
     * customer who had called but not written, and neither could the agent
     * reply after hanging up.
     *
     * The call is its thread note (CallLog): an Info row keyed `call:{id}`,
     * written Outgoing so it stays out of the unread badge — which is why it
     * needs looking for separately. Calls the flow placed do not count; the
     * customer reached out to nobody.
     */
    public static function lastInboundAt(Conversation $conversation): ?Carbon
    {
        $lastInbound = $conversation->messages()
            ->where('sender_type', SenderType::Incoming)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        $lastCall = $conversation->messages()
            ->where('message_type', MessageType::Info)
            ->where('external_id', 'like', 'call:%')
            ->where(fn ($query) => $query->whereNull('meta->call->direction')
                ->orWhere('meta->call->direction', '!=', CallLog::OUTGOING))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        $stamps = array_filter([self::stampOf($lastInbound), self::stampOf($lastCall)]);

        return $stamps === [] ? null : max($stamps);
    }

    /**
     * sent_at is cast to a unix timestamp on read; a row a handler never
     * stamped still tells us when it landed.
     */
    private static function stampOf(?Message $message): ?Carbon
    {
        if (! $message) {
            return null;
        }

        return $message->sent_at !== null
            ? Carbon::createFromTimestamp($message->sent_at)
            : $message->created_at;
    }
}
