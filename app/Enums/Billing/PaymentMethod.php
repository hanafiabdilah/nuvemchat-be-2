<?php

namespace App\Enums\Billing;

enum PaymentMethod: string
{
    case Card = 'card';
    case Pix = 'pix';

    /**
     * A hosted payment page (dLocal Go): the customer is sent to the gateway's
     * own checkout and picks card or a local method there. Paid per cycle,
     * like Pix — nothing is stored, so nothing renews on its own.
     */
    case Checkout = 'checkout';

    case Manual = 'manual';

    /**
     * Methods that are paid by the customer each cycle against a fresh
     * invoice, as opposed to a card we charge (or that renews itself).
     */
    public function isPaidPerCycle(): bool
    {
        return $this === self::Pix || $this === self::Checkout;
    }
}
