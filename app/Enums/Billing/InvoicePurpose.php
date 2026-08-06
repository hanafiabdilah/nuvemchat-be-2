<?php

namespace App\Enums\Billing;

enum InvoicePurpose: string
{
    /** Regular plan subscription charge (the original invoice shape). */
    case Subscription = 'subscription';
    /** First payment of an API Way unit purchase. */
    case ApiwayPurchase = 'apiway_purchase';
    /** Cycle renewal of an API Way unit subscription. */
    case ApiwayRenewal = 'apiway_renewal';

    public function isApiway(): bool
    {
        return $this !== self::Subscription;
    }
}
