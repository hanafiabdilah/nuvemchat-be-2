<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use App\Services\Billing\MercadoPago\MercadoPagoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'billing:reconcile {--hours=48 : Look back this many hours for in-flight invoices}';

    protected $description = 'Safety net: pull MercadoPago status for in-flight payments missed by webhooks';

    public function handle(MercadoPagoClient $mp, BillingService $billing): int
    {
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Pending->value)
            ->whereNotNull('mp_payment_id')
            ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->get();

        $reconciled = 0;

        foreach ($invoices as $invoice) {
            try {
                $payment = $mp->getPayment($invoice->mp_payment_id);
                $billing->applyPaymentUpdate($payment);
                $reconciled++;
            } catch (\Throwable $e) {
                Log::error('Reconcile failed for invoice', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reconciled {$reconciled} invoice(s).");

        return self::SUCCESS;
    }
}
