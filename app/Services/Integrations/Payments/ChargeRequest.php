<?php

namespace App\Services\Integrations\Payments;

use Carbon\CarbonInterface;

/**
 * What a payment node asks a gateway for, already resolved: variables
 * interpolated, amount parsed, payer details found. Gateways only translate it
 * into their own dialect.
 */
final class ChargeRequest
{
    public function __construct(
        /** Ours — the idempotency key, correlation id and external reference. */
        public readonly string $reference,
        /** `pix` or `checkout`. */
        public readonly string $method,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $description,
        public readonly CarbonInterface $expiresAt,
        public readonly ?string $payerName = null,
        public readonly ?string $payerEmail = null,
        public readonly ?string $payerDocument = null,
        public readonly ?string $notificationUrl = null,
    ) {}
}
