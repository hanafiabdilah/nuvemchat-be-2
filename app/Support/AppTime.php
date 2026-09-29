<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A moment an outside service gave us, in the app's own timezone.
 *
 * Gateways answer in their own zones — Mercado Pago in -04:00, Asaas and
 * Spedy in America/Sao_Paulo — and Eloquent writes a datetime by formatting
 * the Carbon instance it is handed, in whatever zone that instance carries,
 * without converting. So "11:24 UTC" arriving as "07:24-04:00" was stored as
 * 07:24 and read back as 07:24 UTC: a Pix that expired four hours before it
 * was created, closed by the expiry job seconds later. Every moment that
 * crosses from a gateway into a column goes through here.
 */
final class AppTime
{
    public static function from(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public static function fromNullable(?CarbonInterface $moment): ?CarbonImmutable
    {
        return $moment === null ? null : self::from($moment);
    }
}
