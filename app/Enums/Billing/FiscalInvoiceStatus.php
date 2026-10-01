<?php

namespace App\Enums\Billing;

/**
 * Where a platform nota fiscal stands.
 *
 *  - pending     queued, not yet accepted by Plugnotas
 *  - processing  accepted, waiting for the prefeitura
 *  - issued      authorized (Plugnotas: CONCLUIDO)
 *  - rejected    the prefeitura refused it (REJEITADO / DENEGADO) — needs an
 *                operator: something in the configuration or in the tomador
 *                is wrong, and resending the same thing gets the same answer
 *  - failed      never reached the prefeitura (configuration missing, request
 *                refused by Plugnotas after every retry)
 *  - cancelling  cancellation requested, waiting for the prefeitura
 *  - cancelled   cancelled (the invoice was refunded)
 */
enum FiscalInvoiceStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Issued = 'issued';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';

    /** Nothing more will happen on its own; only an operator moves it. */
    public function needsOperator(): bool
    {
        return $this === self::Rejected || $this === self::Failed;
    }

    /** Still waiting on someone else — the sweep follows these. */
    public function inFlight(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::Cancelling], true);
    }
}
