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
    /**
     * One-off purchase of a trained agent beyond the plan's included slots.
     * There is no renewal counterpart: the fork is permanent once paid.
     */
    case TrainedAgentPurchase = 'trained_agent_purchase';

    /**
     * A top-up of the prepaid AI credit balance, for workspaces running on a
     * rented platform key. Like the trained agent it has no period and no
     * renewal — the customer buys an amount, spends it, and buys again.
     */
    case AiCreditTopup = 'ai_credit_topup';

    public function isApiway(): bool
    {
        return $this === self::ApiwayPurchase || $this === self::ApiwayRenewal;
    }

    /**
     * Charges with no plan subscription behind them. Their paid hook provisions
     * an asset instead of moving subscription state, so `applyPaymentUpdate`
     * routes them away from the subscription transaction entirely.
     */
    public function isAssetPurchase(): bool
    {
        return $this !== self::Subscription;
    }
}
