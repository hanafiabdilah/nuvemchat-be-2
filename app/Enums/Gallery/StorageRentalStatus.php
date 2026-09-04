<?php

namespace App\Enums\Gallery;

/**
 * Two states, and deliberately no `past_due`.
 *
 * A rental is either paying for space or it is not. The in-between that other
 * billing objects need — "we are still trying, do not take anything away yet" —
 * is here expressed by the renewal window itself: the charge is attempted from
 * three days out and the row stays `active`, with everything it grants intact,
 * until the day it renews. A third status would only mean "active, but we are
 * worried", which is a fact about the balance and not about the rental.
 */
enum StorageRentalStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
