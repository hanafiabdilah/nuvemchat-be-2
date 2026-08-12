<?php

namespace App\Enums\Broadcast;

/**
 * The shape of a recipient's address on a channel, which decides whether a
 * campaign may take typed-in recipients or only contacts that already exist.
 *
 * See Channel::broadcastAddressType().
 */
enum AddressType: string
{
    case Phone = 'phone';
    case Email = 'email';

    /** A platform id (Telegram chat id, Discord user id, IG-scoped id). */
    case Internal = 'internal';

    /** Whether a human could reasonably paste a list of these. */
    public function acceptsManualInput(): bool
    {
        return $this !== self::Internal;
    }

    /**
     * Reduce an address to the form it is stored and de-duplicated by, so the
     * same person picked from contacts and pasted by hand collapses into one
     * recipient. Mirrors what the send paths already do: digits only for a
     * phone number (MessageTemplateController::send), trimmed lowercase for an
     * e-mail (ConversationController::composeEmail).
     */
    public function normalize(string $address): string
    {
        return match ($this) {
            self::Phone => preg_replace('/\D+/', '', $address) ?? '',
            self::Email => strtolower(trim($address)),
            self::Internal => trim($address),
        };
    }

    public function isValid(string $normalized): bool
    {
        return match ($this) {
            // Short enough to be a typo, long enough to be a country code plus
            // a number: E.164 allows 15 digits, and nothing real is under 8.
            self::Phone => strlen($normalized) >= 8 && strlen($normalized) <= 15,
            self::Email => filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false,
            self::Internal => $normalized !== '',
        };
    }
}
