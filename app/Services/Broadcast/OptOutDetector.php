<?php

namespace App\Services\Broadcast;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Message;

/**
 * Notices a customer asking to stop receiving campaigns, and records it.
 *
 * WhatsApp requires a business sending marketing templates to honour opt-outs,
 * and the customer's only way of expressing one is to reply to the thread —
 * there is no unsubscribe endpoint to receive. So the request arrives as an
 * ordinary inbound message and something has to read it.
 *
 * Deliberately narrow. It matches only a message that is *nothing but* a stop
 * word, so "não quero parar de receber" and "vou cancelar meu pedido" do not
 * silently remove someone from every future campaign. A customer who means it
 * types the word on its own — that is what the footer of a marketing template
 * tells them to do.
 *
 * Opting back in is a deliberate act by an agent (or the contact endpoint), not
 * something inferred from the next message they send.
 */
class OptOutDetector
{
    /**
     * pt-BR first — this platform's tenants are Brazilian — plus the English
     * words WhatsApp itself suggests in its opt-out guidance.
     */
    private const STOP_WORDS = [
        'parar',
        'pare',
        'sair',
        'cancelar',
        'descadastrar',
        'remover',
        'stop',
        'unsubscribe',
    ];

    /**
     * Called for every message that lands. Returns early on anything that is
     * not a plain inbound text so the common path costs one enum comparison.
     */
    public static function noteInboundMessage(Message $message): void
    {
        if ($message->sender_type !== SenderType::Incoming || $message->message_type !== MessageType::Text) {
            return;
        }

        if (! self::isStopWord($message->body)) {
            return;
        }

        $conversation = $message->getRelationValue('conversation');

        // In a group the sender is on the message, not the conversation — but a
        // group cannot receive a campaign in the first place, so there is
        // nothing to opt out of.
        if (! $conversation || $conversation->isGroup()) {
            return;
        }

        $contact = $conversation->getRelationValue('contact');

        if ($contact && ! $contact->hasOptedOutOfBroadcasts()) {
            $contact->update(['broadcast_opted_out_at' => now()]);
        }
    }

    public static function isStopWord(?string $body): bool
    {
        $normalized = mb_strtolower(trim((string) $body));

        // Strip trailing punctuation ("PARAR!") and the accents a phone keyboard
        // may or may not have supplied.
        $normalized = rtrim($normalized, ".!?,;:");
        $normalized = self::stripAccents($normalized);

        return $normalized !== '' && in_array($normalized, self::STOP_WORDS, true);
    }

    private static function stripAccents(string $value): string
    {
        return strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'î' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }
}
