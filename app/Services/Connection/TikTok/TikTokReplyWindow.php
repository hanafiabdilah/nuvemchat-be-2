<?php

namespace App\Services\Connection\TikTok;

use App\Enums\Message\SenderType;
use App\Exceptions\ConnectionException;
use App\Models\Conversation;
use Carbon\Carbon;

/**
 * TikTok Business Messaging only allows a business to reply within 48 hours
 * of the user's last message — it can never initiate. Enforced before every
 * send so the agent gets a clear error instead of an opaque API rejection.
 */
class TikTokReplyWindow
{
    public const HOURS = 48;

    public static function isOpen(Conversation $conversation): bool
    {
        $lastInbound = $conversation->messages()
            ->where('sender_type', SenderType::Incoming)
            ->orderByDesc('sent_at')
            ->first();

        if (! $lastInbound || $lastInbound->sent_at === null) {
            return false;
        }

        // sent_at is cast to a unix timestamp on read.
        return Carbon::createFromTimestamp($lastInbound->sent_at)
            ->gt(now()->subHours(self::HOURS));
    }

    public static function assertOpen(Conversation $conversation): void
    {
        if (! self::isOpen($conversation)) {
            throw new ConnectionException(
                'The TikTok 48-hour reply window for this conversation has closed. You can reply again after the user sends a new message.',
                422
            );
        }
    }
}
