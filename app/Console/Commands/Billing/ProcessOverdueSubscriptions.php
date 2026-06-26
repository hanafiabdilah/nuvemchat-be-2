<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;

class ProcessOverdueSubscriptions extends Command
{
    protected $signature = 'billing:process-overdue';

    protected $description = 'Move overdue subscriptions through past_due → grace → suspended and expire stale pix charges';

    public function handle(BillingService $billing): int
    {
        // 1. Expire stale (unpaid, past-expiry) pix invoices.
        $expired = Invoice::query()
            ->where('status', InvoiceStatus::Pending->value)
            ->whereNotNull('pix_expires_at')
            ->where('pix_expires_at', '<', now())
            ->update(['status' => InvoiceStatus::Expired->value]);

        // 2. Active subscriptions whose period has lapsed with no payment → past_due (+grace).
        $lapsed = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now())
            ->get();

        foreach ($lapsed as $subscription) {
            $billing->markPastDue($subscription);
        }

        // 3. past_due subscriptions whose grace window has closed → suspended.
        $toSuspend = Subscription::query()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', now())
            ->get();

        foreach ($toSuspend as $subscription) {
            $billing->suspend($subscription);
        }

        $this->info("Expired {$expired} pix invoice(s); {$lapsed->count()} lapsed; {$toSuspend->count()} suspended.");

        return self::SUCCESS;
    }
}
