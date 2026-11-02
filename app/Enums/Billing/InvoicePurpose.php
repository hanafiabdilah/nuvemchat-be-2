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
     * A top-up of the prepaid balance. No period and no renewal — the customer
     * buys an amount, spends it, and buys again.
     *
     * ⚠️ The case was renamed when the wallet stopped being AI-only; the stored
     * value was not. It names rows already sitting in `invoices.purpose`, and a
     * value renamed in code is a set of historical invoices that stop casting.
     */
    case CreditTopup = 'ai_credit_topup';

    /**
     * Extras (agents, WhatsApp connections) bought on a running plan: what is
     * left of the paid period, prorated. The units are added once it is paid
     * (SubscriptionAddons::handleInvoicePaid); from the next renewal on they
     * ride on the plan's own charge, so this purpose never renews.
     */
    case SubscriptionAddon = 'subscription_addon';

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
