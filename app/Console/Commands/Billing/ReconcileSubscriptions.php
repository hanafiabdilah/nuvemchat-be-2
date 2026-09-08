<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\PaymentServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The fallback for a webhook that never arrived.
 *
 * The payment service retries a delivery eight times over about ten hours and
 * then gives up — which is only acceptable because of this: every in-flight
 * charge is re-read straight from the service, so a lost notification costs a
 * delay rather than a payment nobody noticed.
 *
 * Idempotent by construction. Re-applying a payment that already settled its
 * invoice is a no-op inside applyPaymentUpdate().
 */
class ReconcileSubscriptions extends Command
{
    protected $signature = 'billing:reconcile
                            {--hours=48 : Look back this many hours for in-flight charges}
                            {--invoice= : Reconcile only this invoice id}';

    protected $description = 'Safety net: re-read in-flight charges from the payment service without waiting for webhooks.';

    public function handle(PaymentServiceClient $payments, BillingService $billing): int
    {
        $invoices = Invoice::query()
            ->when(
                $this->option('invoice'),
                fn ($q) => $q->whereKey((int) $this->option('invoice')),
                fn ($q) => $q
                    ->where('status', InvoiceStatus::Pending->value)
                    ->where('created_at', '>=', now()->subHours((int) $this->option('hours'))),
            )
            // Nothing to ask about without an id: the create call never came
            // back, and there is no charge on the other side to reconcile with.
            ->whereNotNull('payment_id')
            ->get();

        $reconciled = 0;

        foreach ($invoices as $invoice) {
            try {
                $billing->applyPaymentUpdate($payments->getPayment($invoice->payment_id));
                $reconciled++;
            } catch (\Throwable $e) {
                Log::error('Reconcile failed for invoice', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reconciled {$reconciled} of {$invoices->count()} in-flight charge(s).");

        return self::SUCCESS;
    }
}
