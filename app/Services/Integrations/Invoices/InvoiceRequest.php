<?php

namespace App\Services\Integrations\Invoices;

/**
 * What an invoice node asks a platform for, already resolved: variables
 * interpolated, amount parsed, the customer's document reduced to digits.
 * Drivers only translate it into their own dialect.
 *
 * No fiscal codes here. What service is being invoiced (LC 116 item, municipal
 * code, CNAE) is a property of the business, set once on the integration — a
 * workspace selling two kinds of service connects the same account twice with
 * two sets of defaults, and each node picks the one it means.
 */
final class InvoiceRequest
{
    /**
     * @param  array{postal_code?: string, street?: string, number?: string, complement?: string, district?: string, city?: string, state?: string}|null  $customerAddress
     */
    public function __construct(
        /** Ours — the integration id / external reference that makes a retry idempotent. */
        public readonly string $reference,
        public readonly int $amountCents,
        /** What was sold, as printed on the document (discriminação do serviço). */
        public readonly string $description,
        public readonly string $customerName,
        /** CPF (11 digits) or CNPJ (14 digits), digits only. */
        public readonly string $customerDocument,
        public readonly ?string $customerEmail = null,
        public readonly ?array $customerAddress = null,
        /** Whether the platform should e-mail the document to the customer itself. */
        public readonly bool $sendEmail = false,
        public readonly ?string $notificationUrl = null,
    ) {}

    public function isCompany(): bool
    {
        return strlen($this->customerDocument) === 14;
    }
}
