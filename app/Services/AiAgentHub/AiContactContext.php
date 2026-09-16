<?php

namespace App\Services\AiAgentHub;

use App\Enums\Connection\Channel;
use App\Models\Conversation;
use App\Services\Contact\ContactIdentity;

/**
 * Who the customer is, in the fields an agent can act on: their phone number,
 * a name worth using, and where the number came from.
 *
 * The run payload has always carried `contactExternalId`, and on WhatsApp that
 * *is* the phone number — which is why a partner reading it to match a customer
 * against their own records mostly works. The trouble is the channels where it
 * is not: a Telegram chat id, an Instagram scoped id, a Discord user id. Nothing
 * in the payload says which one arrived, so an eleven-digit Telegram chat id
 * compared against a registered phone can match. On a field that decides whether
 * to hand over somebody's subscription details, a false positive is the worst
 * outcome available.
 *
 * So the phone is sent as its own key, present only when the channel really
 * addresses people by phone, and absent — never "best effort" — otherwise. The
 * question "is this address a phone number?" is already answered once in this
 * codebase (Channel::broadcastAddressType, via ContactIdentity) and that answer
 * is reused rather than a second one written here.
 */
final class AiContactContext
{
    /**
     * @return array<string, string> merged into the run's `conversation` object;
     *                               empty when there is nothing trustworthy to add
     */
    public static function for(Conversation $conversation): array
    {
        $contact = $conversation->contact;
        $channel = $conversation->connection?->channel;

        if (! $contact || ! $channel) {
            return [];
        }

        $context = [];

        // Groups return nothing from ContactIdentity (a group is not a person),
        // and an AI node never runs in one — this is belt and braces.
        $phone = ContactIdentity::for($contact)['phone'] ?? null;

        if ($phone !== null) {
            $context['contactPhone'] = $phone;

            $source = self::phoneSource($channel);

            if ($source !== null) {
                $context['phoneSource'] = $source;
            }
        }

        // `contactName` is already sent and stays as it is, but it is often not
        // a name: it holds the number itself for a contact that never shared a
        // profile, and a placeholder derived from the JID on API Way. Addressing
        // a customer as "5511987654321" is the failure this avoids.
        $name = ContactIdentity::name($contact);

        if ($name !== null) {
            $context['contactDisplayName'] = $name;
        }

        return $context;
    }

    /**
     * How much the number is worth as evidence.
     *
     * Both are fine for answering a customer. They are not equal when the
     * question is whether to release account data, and the difference is not
     * something the hub can see from here:
     *
     *  - `meta_wa_id` — Meta states the number in the webhook itself.
     *  - `whatsmeow`  — derived from the JIDs an unofficial client reports, and
     *    on a re-delivered message resolved through an alias we recorded
     *    earlier (see the LID handling in WhatsappApiwayHandler).
     *
     * A channel we have not characterised sends no source at all rather than a
     * guess: absent means unknown provenance, which the reader should treat as
     * the weaker of the two, and a claim we cannot stand behind is worse than
     * no claim.
     */
    private static function phoneSource(Channel $channel): ?string
    {
        return match ($channel) {
            Channel::WhatsappOfficial => 'meta_wa_id',
            Channel::WhatsappApiway => 'whatsmeow',
            default => null,
        };
    }
}
