<?php

namespace App\Enums\Catalog;

/**
 * Where an order the AI took stands.
 *
 * `open` is a cart still being filled in the conversation. Charging it moves it
 * to `awaiting_payment`, and from there the gateway decides: `paid` (stock is
 * taken), or `expired` when the Pix ran out. `cancelled` is a charge that could
 * not even be created — the cart is closed so the next attempt starts clean.
 */
enum OrderStatus: string
{
    case Open = 'open';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isClosed(): bool
    {
        return in_array($this, [self::Paid, self::Expired, self::Cancelled], true);
    }
}
