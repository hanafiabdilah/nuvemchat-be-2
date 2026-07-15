<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\MercadoPago\MercadoPagoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'billing:reconcile
                            {--hours=48 : Look back this many hours for in-flight pix invoices}
                            {--id= : Reconcile only this subscription id (card pull)}';

    protected $description = 'Safety net / pull model: reconcile in-flight pix payments and card subscriptions (preapproval + recurring charges) straight from MercadoPago, without waiting for webhooks.';

    public function handle(MercadoPagoClient $mp, BillingService $billing): int
    {
        // Single subscription (manual card check) — handy when webhooks aren't configured.
        if ($this->option('id')) {
            $sub = Subscription::find((int) $this->option('id'));
            if (! $sub) {
                $this->error('Subscription not found.');
                return self::FAILURE;
            }
            $r = $billing->syncCardSubscription($sub);
            $this->info("Sub #{$sub->id}: preapproval={$r['preapproval_status']} applied={$r['applied']} linked={$r['linked']} → period_end={$sub->fresh()->current_period_end}");
            return self::SUCCESS;
        }

        // 1) Pending pix invoices missed by webhooks.
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Pending->value)
            ->whereNotNull('mp_payment_id')
            ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->get();

        $reconciled = 0;
        foreach ($invoices as $invoice) {
            try {
                $billing->applyPaymentUpdate($mp->getPayment($invoice->mp_payment_id));
                $reconciled++;
            } catch (\Throwable $e) {
                Log::error('Reconcile failed for invoice', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Reconciled {$reconciled} pix invoice(s).");

        // 2) Card subscriptions — pull preapproval status + recurring charges.
        $cards = Subscription::query()
            ->where('payment_method', PaymentMethod::Card->value)
            ->whereNotNull('mp_preapproval_id')
            ->whereNotIn('status', [SubscriptionStatus::Cancelled->value])
            ->get();

        $applied = 0;
        foreach ($cards as $sub) {
            try {
                $r = $billing->syncCardSubscription($sub);
                $applied += $r['applied'];
            } catch (\Throwable $e) {
                Log::error('Reconcile failed for card subscription', ['subscription_id' => $sub->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Synced {$cards->count()} card subscription(s), {$applied} renewal(s) applied.");

        return self::SUCCESS;
    }
}
