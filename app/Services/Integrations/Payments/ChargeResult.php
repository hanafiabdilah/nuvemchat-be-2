<?php

namespace App\Services\Integrations\Payments;

use App\Enums\Flow\FlowPaymentStatus;
use App\Support\AppTime;
use Carbon\CarbonInterface;

/** A charge the gateway accepted, and what the customer is to be sent. */
final class ChargeResult
{
    public function __construct(
        public readonly string $providerPaymentId,
        public readonly FlowPaymentStatus $status,
        /** The Pix copy-and-paste code (EMV "BR Code"), which the QR encodes. */
        public readonly ?string $pixCode,
        /** A page the customer can open to pay. */
        public readonly ?string $paymentUrl,
        CarbonInterface $expiresAt,
    ) {
        // In the app's zone, whatever zone the gateway answered in — see AppTime.
        $this->expiresAt = AppTime::from($expiresAt);
    }

    public readonly CarbonInterface $expiresAt;
}
