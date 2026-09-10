<?php

namespace App\Services\Contact;

use App\Enums\Broadcast\AddressType;
use App\Models\Contact;

/**
 * Who a contact is, in the fields outside systems ask for: phone, e-mail, name.
 *
 * A contact row has none of those columns. What it has is `external_id`, and
 * what that holds depends on the channel — a phone number on WhatsApp, an
 * address on e-mail, an opaque platform id everywhere else — which is the same
 * question campaigns already answer through Channel::broadcastAddressType().
 * So that answer is reused rather than a second guess made here: a Telegram
 * chat id is never reported to an ad account as somebody's phone.
 */
final class ContactIdentity
{
    /**
     * @return array<string, string> external_id always; phone, email,
     *                               first_name, last_name when known
     */
    public static function for(?Contact $contact): array
    {
        if (! $contact || $contact->is_group) {
            return [];
        }

        $identity = ['external_id' => 'contact-'.$contact->id];

        $type = $contact->channel?->broadcastAddressType();
        $address = (string) $contact->external_id;

        if ($type === AddressType::Phone) {
            $phone = AddressType::Phone->normalize($address);
            if (AddressType::Phone->isValid($phone)) {
                $identity['phone'] = $phone;
            }
        } elseif ($type === AddressType::Email) {
            $email = AddressType::Email->normalize($address);
            if (AddressType::Email->isValid($email)) {
                $identity['email'] = $email;
            }
        }

        [$first, $last] = self::splitName($contact);
        if ($first !== null) {
            $identity['first_name'] = $first;
        }
        if ($last !== null) {
            $identity['last_name'] = $last;
        }

        return $identity;
    }

    /**
     * A display name worth sending, or null when the "name" is really the
     * number or the address — which is what a contact that never shared a
     * profile is called.
     */
    public static function name(?Contact $contact): ?string
    {
        $name = trim((string) $contact?->name);

        if ($name === '' || $name === (string) $contact?->external_id) {
            return null;
        }

        if (preg_match('/^[\d\s+()\-]+$/', $name) === 1 || str_contains($name, '@')) {
            return null;
        }

        return $name;
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function splitName(Contact $contact): array
    {
        $name = self::name($contact);

        if ($name === null) {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = array_shift($parts);

        return [$first ?: null, $parts !== [] ? implode(' ', $parts) : null];
    }
}
