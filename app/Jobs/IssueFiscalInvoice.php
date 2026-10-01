<?php

namespace App\Jobs;

use App\Enums\Billing\FiscalInvoiceStatus;
use App\Models\FiscalInvoice;
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send one nota fiscal to Plugnotas.
 *
 * Retried only on transport failures (timeout, 5xx): a refusal is final and the
 * service marks the row failed itself. Safe to run twice — a second submission
 * of the same reference is refused there and adopted. After the last try the
 * row stays pending and `fiscal-invoices:sync` sends it again later, so a
 * Plugnotas outage delays notas instead of losing them.
 */
class IssueFiscalInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 90;

    public function __construct(public int $fiscalInvoiceId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(FiscalInvoiceService $notas): void
    {
        $row = FiscalInvoice::find($this->fiscalInvoiceId);

        if ($row === null || $row->status !== FiscalInvoiceStatus::Pending) {
            return;
        }

        $notas->submit($row);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('IssueFiscalInvoice: gave up; the sweep will send it again', [
            'fiscal_invoice_id' => $this->fiscalInvoiceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
