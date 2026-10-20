<?php

namespace App\Services\Billing\Gateways;

/**
 * A gateway whose stored card can be swapped by the customer, on our page,
 * for a subscription we renew ourselves (Stripe).
 *
 * Two things a card subscription needs the moment its card stops working, and
 * neither existed: a way to put a new card behind the plan *before* it is
 * refused, and a way to stop a charge the customer started and walked away
 * from (an unfinished 3-D Secure), so a second attempt is never racing it.
 *
 * Keeping a card is not a charge. The normalized answer:
 *
 *     id, status (ready|pending|declined|unknown), client_secret (pending only),
 *     instrument {id}|null, customer {id}|null, card {brand, last_four,
 *     exp_month, exp_year}|null, decline {category, code, advice}|null
 *
 * `pending` with a client secret is the bank asking for the cardholder; the
 * page finishes it with the gateway's SDK and reads the card back.
 */
interface KeepsRenewalCards
{
    /**
     * @param  array<string, mixed>  $payload  card_token, customer_id?, customer, metadata
     * @return array<string, mixed>
     */
    public function keepCard(array $payload, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function readKeptCard(string $id): array;

    /** Best effort: a charge nobody can finish any more is simply left to expire. */
    public function cancelPayment(string $id): void;
}
