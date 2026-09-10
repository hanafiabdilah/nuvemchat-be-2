<?php

namespace App\Services\Integrations\Payments;

use App\Enums\Flow\FlowPaymentStatus;
use Carbon\CarbonInterface;

/** Where a charge stands, as read back from the gateway. */
final class ChargeStatus
{
    public function __construct(
        public readonly FlowPaymentStatus $status,
        public readonly ?CarbonInterface $paidAt = null,
        /** The gateway's own word for it, kept for the log and the list. */
        public readonly ?string $providerStatus = null,
        /** Mercado Pago's checkout only knows the payment id once one exists. */
        public readonly ?string $providerPaymentId = null,
    ) {}
}
