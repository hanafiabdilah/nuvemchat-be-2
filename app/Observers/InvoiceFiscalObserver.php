<?php

namespace App\Observers;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * The one place a paid invoice turns into a nota fiscal.
 *
 * An invoice becomes paid on many paths — the payment webhook, a card charged
 * at checkout, a Mercado Pago preapproval debit (which *creates* the invoice
 * already paid), a renewal, a top-up — and a nota per call site would be
 * complete the day it was written and quietly incomplete after the next one.
 *
 * After commit, because most of those paths run inside a transaction: queueing
 * a nota for a payment that then rolls back would issue a fiscal document for
 * money that never arrived.
 *
 * Never throws: a nota is a consequence of the payment, not a condition for it.
 */
class InvoiceFiscalObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            $this->guard(fn () => app(FiscalInvoiceService::class)->queueFor($invoice));
        }
    }

    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        match ($invoice->status) {
            InvoiceStatus::Paid => $this->guard(fn () => app(FiscalInvoiceService::class)->queueFor($invoice)),
            InvoiceStatus::Refunded => $this->guard(fn () => app(FiscalInvoiceService::class)->cancelFor($invoice)),
            default => null,
        };
    }

    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::error('Nota fiscal hook failed', ['error' => $e->getMessage()]);
        }
    }
}
