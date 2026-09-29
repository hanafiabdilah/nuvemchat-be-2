<?php

namespace App\Services\Billing\Gateways;

use App\Models\Tenant;

/**
 * What BillingService needs from whoever takes the money.
 *
 * Shaped after the payment service's own contract, because that is the one the
 * billing code was written against: amounts in minor units, an order reference
 * that makes a charge exactly-once, and a *normalized payment* coming back —
 *
 *     id, status (paid|pending|created|unknown|authorized|declined|voided|
 *     expired|refunded|partially_refunded|disputed), order_reference,
 *     instructions {qr_code, qr_code_image, redirect_url, expires_at},
 *     instrument {id}, customer {id}, decline {category, code}
 *
 * The direct gateways translate their own vocabulary into that shape at the
 * edge, so nothing above this interface knows which company answered.
 *
 * ⚠️ `unknown` keeps its meaning everywhere: the gateway did not answer, so
 * nobody knows whether the charge exists. It is never a failure.
 */
interface BillingGateway
{
    /** payment_service | mercadopago | dlocalgo — stored on invoices and subscriptions. */
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * What can be charged right now in this currency.
     *
     * @return list<array<string, mixed>>  {method, instruction_type, merchant_initiated_cards, public_key, …}
     */
    public function paymentMethods(?string $currency): array;

    /**
     * What the browser needs to tokenise a card with this gateway's SDK.
     *
     * @return array{sdk: ?string, public_key: ?string, provider: ?string}
     */
    public function cardSession(Tenant $tenant): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>  Envelope: `data` holds the normalized payment.
     */
    public function createPayment(array $payload, string $idempotencyKey): array;

    /** @return array<string, mixed> The normalized payment. */
    public function getPayment(string $id): array;

    /**
     * Whether the gateway charges this stored instrument on its own schedule
     * (a Mercado Pago preapproval), in which case `billing:charge-renewals`
     * must leave it alone and a cancellation has to be told to the gateway.
     */
    public function renewsItself(string $instrumentId): bool;

    /**
     * Pause, resume or end a standing authorisation. A no-op where there is
     * none: an instrument we charge ourselves stops being charged the moment we
     * stop asking.
     *
     * @param  'paused'|'active'|'cancelled'  $state
     */
    public function setRecurringState(string $instrumentId, string $state): void;
}
