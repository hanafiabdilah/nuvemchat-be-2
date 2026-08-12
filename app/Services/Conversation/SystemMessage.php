<?php

namespace App\Services\Conversation;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\MessageReceived;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Notes the platform writes into a thread. They are stored as messages so they
 * take their place in the timeline and reach every open tab over the usual
 * broadcast — but they are never handed to a channel: nothing is sent to the
 * customer.
 */
class SystemMessage
{
    /**
     * Write an info note into the conversation and broadcast it.
     *
     * `sender_type` has to hold one of the two directions the column allows.
     * Outgoing is the honest half (the platform produced this, not the
     * customer) and keeps these rows out of the unread badge, which only
     * counts Incoming. The chat panel branches on message_type before it ever
     * looks at direction, so an info note is never drawn as an agent bubble.
     *
     * @param string|null          $code   Machine name for the note, so the SPA
     *                                     can render it in the reader's
     *                                     language; `body` is the fallback for
     *                                     anything it does not recognise.
     * @param array<string, mixed> $params Values the translated copy needs.
     */
    public static function info(Conversation $conversation, string $body, ?string $code = null, array $params = []): Message
    {
        $message = $conversation->messages()->create([
            'sender_type' => SenderType::Outgoing,
            'message_type' => MessageType::Info,
            'body' => $body,
            'sent_at' => now(),
            'meta' => $code ? ['info' => array_filter([
                'code' => $code,
                'params' => $params ?: null,
            ])] : null,
        ]);

        broadcast(new MessageReceived($message));

        return $message;
    }
}
