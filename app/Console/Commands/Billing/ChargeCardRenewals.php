<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renew card subscriptions by charging the stored instrument.
 *
 * ⚠️ This command *is* the renewal clock. It used to belong to MercadoPago — a
 * preapproval debited the card on a schedule of its own and told us afterwards.
 * The payment service has no such object: a stored instrument is charged when
 * somebody asks, and nobody else is going to ask. So if this stops running, no
 * card subscription renews, and every one of them lapses into grace and then
 * suspension without a single error being raised.
 *
 * Safe to run as often as you like. Each cycle's charge is keyed on an order
 * reference built from the subscription and the period, which is unique in the
 * payment service's database — so a double firing, two workers racing, and an
 * operator running it by hand all converge on one payment.
 */
class ChargeCardRenewals extends Command
{
    protected $signature = 'billing:charge-renewals
                            {--days-before=3 : Charge this many days before the period ends}
                            {--id= : Only this subscription}';

    protected $description = 'Charge stored cards for subscriptions nearing the end of their period.';

    public function handle(BillingService $billing): int
    {
        Heartbeat::ping('billing:charge-renewals');

        $threshold = now()->addDays((int) $this->option('days-before'));

        $subscriptions = Subscription::query()
            ->where('payment_method', PaymentMethod::Card->value)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                // Past due too: the grace window exists so a temporary decline
                // (no funds today, funds on Friday) has somewhere to recover,
                // and that only works if something tries again.
                SubscriptionStatus::PastDue->value,
            ])
            ->where('cancel_at_period_end', false)
            // No instrument, nothing to charge. Either the card was never
            // stored or the service told us it stopped working — both are the
            // customer's move, not a call worth making.
            ->whereNotNull('payment_instrument_id')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $threshold)
            ->when($this->option('id'), fn ($q) => $q->whereKey((int) $this->option('id')))
            ->get();

        $charged = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $invoice = $billing->chargeRenewal($subscription);

                if ($invoice === null) {
                    continue;
                }

                $invoice->status->value === 'paid' ? $charged++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('ChargeCardRenewals: charge failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Checked {$subscriptions->count()} due card subscription(s); {$charged} renewed, {$failed} not.");

        return self::SUCCESS;
    }
}
