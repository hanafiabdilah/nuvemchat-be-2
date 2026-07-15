<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pull-model auto-renewal for card subscriptions — the automatic replacement for the
 * MercadoPago webhook when it can't be configured. Runs frequently but only touches
 * subscriptions that are near/at their renewal boundary (or already overdue), so it
 * stays cheap on API calls. For each, it fetches the preapproval status + the latest
 * charges and advances the period when a new approved charge is found.
 */
class PullCardRenewals extends Command
{
    protected $signature = 'billing:pull-cards
                            {--window=6 : Only sync card subs whose period ends within this many hours (or already ended)}';

    protected $description = 'Poll MercadoPago for card subscription renewals (no webhook required) and extend paid periods.';

    public function handle(BillingService $billing): int
    {
        $windowEnd = now()->addHours((int) $this->option('window'));

        $subscriptions = Subscription::query()
            ->where('payment_method', PaymentMethod::Card->value)
            ->whereNotNull('mp_preapproval_id')
            ->whereNotIn('status', [SubscriptionStatus::Cancelled->value])
            // Due soon or overdue — a subscription comfortably inside its period doesn't
            // need polling yet, which keeps MercadoPago API traffic proportional to churn.
            ->where(function ($q) use ($windowEnd) {
                $q->whereNull('current_period_end')
                  ->orWhere('current_period_end', '<=', $windowEnd);
            })
            ->get();

        $applied = 0;
        foreach ($subscriptions as $sub) {
            try {
                $r = $billing->syncCardSubscription($sub);
                $applied += $r['applied'];
            } catch (\Throwable $e) {
                Log::error('PullCardRenewals: sync failed', ['subscription_id' => $sub->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Checked {$subscriptions->count()} due card subscription(s); {$applied} renewal(s) applied.");

        return self::SUCCESS;
    }
}
