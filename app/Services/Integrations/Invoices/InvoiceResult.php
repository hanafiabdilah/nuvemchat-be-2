<?php

namespace App\Services\Integrations\Invoices;

use App\Enums\Flow\FlowInvoiceStatus;
use Carbon\CarbonInterface;

/** Where an invoice stands at the provider — right after the request, or read back later. */
final class InvoiceResult
{
    public function __construct(
        public readonly FlowInvoiceStatus $status,
        public readonly ?string $providerInvoiceId = null,
        /** The document number the authority assigned, once it did. */
        public readonly ?string $number = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $xmlUrl = null,
        public readonly ?CarbonInterface $issuedAt = null,
        /** The authority's or the provider's reason, already in our words. */
        public readonly ?string $failureReason = null,
        /** The provider's own word for the state, for the log and the list. */
        public readonly ?string $providerStatus = null,
    ) {}
}
