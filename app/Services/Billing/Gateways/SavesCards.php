<?php

namespace App\Services\Billing\Gateways;

/**
 * A gateway that keeps cards for a customer (Mercado Pago customers & cards).
 *
 * The card lives at the gateway; we keep only a pointer (see SavedCard). Every
 * charge on a kept card still needs a fresh token, minted in the browser from
 * the card id and the security code — the gateway never stores the CVV, so no
 * charge on a saved card can happen without someone typing it. That is also
 * why a subscription on a saved card is still a gateway-held recurring
 * authorisation, not a card we charge ourselves each month.
 */
interface SavesCards
{
    /**
     * The gateway customer for this payer, found by email or created.
     *
     * @param  array<string, mixed>  $customer  email, name, document_type, document_number
     */
    public function findOrCreateCustomer(array $customer): string;

    /**
     * Attach the card behind a single-use token to the customer.
     *
     * @return array{id: string, brand: ?string, payment_type: ?string, issuer_id: ?string,
     *               first_six: ?string, last_four: ?string, exp_month: ?int, exp_year: ?int, holder_name: ?string}
     */
    public function saveCard(string $customerId, string $cardToken): array;

    /** Detach it. A card the gateway no longer has is already the outcome wanted. */
    public function deleteCard(string $customerId, string $cardId): void;
}
