<?php

namespace App\Enums\AiToken;

/**
 * Lifecycle of a platform-owned provider key in the rental pool.
 *
 * `Paused` and `Revoked` are deliberately different states even though neither
 * takes new tenants. Paused is a rate-limit decision — the key is healthy, it
 * is simply full, and the workspaces already on it keep working. Revoked means
 * the secret itself is gone (rotated at the provider, leaked, cancelled), and
 * every workspace on it is broken until it is moved: the two need opposite
 * reactions, so they cannot share a status.
 */
enum TokenPoolKeyStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Revoked = 'revoked';

    /** May new rentals land on this key? */
    public function acceptsRentals(): bool
    {
        return $this === self::Active;
    }

    /** Do the tenants already on this key have to be moved off it? */
    public function requiresRotation(): bool
    {
        return $this === self::Revoked;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
