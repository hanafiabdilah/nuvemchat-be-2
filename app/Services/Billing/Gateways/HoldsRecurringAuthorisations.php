<?php

namespace App\Services\Billing\Gateways;

/**
 * A gateway whose card subscriptions are a standing authorisation for a fixed
 * amount, charged on the gateway's own schedule (a Mercado Pago preapproval).
 *
 * Two consequences the plan-change code has to know about:
 *  - the first charge of a new card subscription *is* that fixed amount, so it
 *    cannot carry a one-off proration discount — the credit goes to the balance;
 *  - a scheduled downgrade has to change the amount at the gateway, or the next
 *    cycle is charged at the old price.
 */
interface HoldsRecurringAuthorisations
{
    /** Change what every later cycle of the authorisation charges. */
    public function updateRecurringAmount(string $instrumentId, int $amountCents, string $currency): void;
}
