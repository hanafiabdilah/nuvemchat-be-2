<?php

namespace App\Jobs;

use App\Models\FlowInvoice;
use App\Services\Flow\FlowInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The moment an invoice node stops waiting for the authority.
 *
 * Armed for just after the node's deadline. Safe to run late, twice, or after
 * the invoice already settled: release() is idempotent and leaves anything no
 * longer processing alone. The flow-invoices:sync sweep covers a job that never
 * ran at all.
 */
class ReleaseFlowInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $invoiceId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(FlowInvoiceService $invoices): void
    {
        $invoice = FlowInvoice::find($this->invoiceId);

        if ($invoice !== null && $invoice->isProcessing()) {
            $invoices->release($invoice);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ReleaseFlowInvoice: gave up; the sweep will release it', [
            'flow_invoice_id' => $this->invoiceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
