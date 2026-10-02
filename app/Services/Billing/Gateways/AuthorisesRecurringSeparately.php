<?php

namespace App\Services\Billing\Gateways;

/**
 * A gateway whose card subscription is two calls, not one: the first cycle is
 * an ordinary card payment, and the standing authorisation for the cycles after
 * it is set up only once that payment went through, starting when the paid
 * period ends (Mercado Pago: `/v1/payments`, then a preapproval with
 * `start_date`).
 *
 * Why not the one call: a preapproval asked to charge *now* is validated by
 * Mercado Pago as a new recurring mandate on an unproven card, and production
 * refused every such attempt with `CC_VAL_433` — while the same saved cards paid
 * a one-off `/v1/payments` charge without trouble. The two-step shape is the
 * one ProxyBR runs on the same Mercado Pago account.
 *
 * The browser mints two single-use tokens from the same saved card and CVV:
 * one pays, the other becomes the authorisation.
 */
interface AuthorisesRecurringSeparately
{
    /**
     * Set up the renewals. Throws on refusal; the first cycle is already paid
     * by then, so the caller records the failure and keeps the plan.
     *
     * @param  array{
     *     amount: int,
     *     currency: string,
     *     card_token: string,
     *     start_date: \DateTimeInterface,
     *     recurring: array{frequency: int, frequency_type: string},
     *     description?: string,
     *     customer?: array<string, mixed>,
     *     subscription_id: int,
     * }  $payload
     * @return string The instrument id to keep on the subscription.
     */
    public function authoriseRecurring(array $payload, string $idempotencyKey): string;
}
