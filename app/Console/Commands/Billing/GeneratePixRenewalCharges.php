<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GeneratePixRenewalCharges extends Command
{
    protected $signature = 'billing:pix-generate
                            {--days-before=3 : Generate a renewal charge this many days before period end}';

    protected $description = 'Generate fresh Pix / checkout charges for subscriptions nearing the end of their period';

    public function handle(BillingService $billing): int
    {
        $daysBefore = (int) $this->option('days-before');
        $threshold = now()->addDays($daysBefore);

        $subscriptions = Subscription::query()
            // Every subscription paid by hand each cycle: Pix, or a hosted
            // checkout link (dLocal Go, direct billing outside Brazil).
            ->whereIn('payment_method', [PaymentMethod::Pix->value, PaymentMethod::Checkout->value])
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->where('cancel_at_period_end', false)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $threshold)
            ->get();

        $generated = 0;

        foreach ($subscriptions as $subscription) {
            // Skip if there's already an open pending pix invoice for this cycle.
            $hasOpen = $subscription->invoices()
                ->where('status', InvoiceStatus::Pending->value)
                ->whereIn('payment_method', [PaymentMethod::Pix->value, PaymentMethod::Checkout->value])
                ->exists();

            if ($hasOpen) {
                continue;
            }

            try {
                $billing->createCycleInvoice($subscription, $billing->renewalMethodFor($subscription));
                $generated++;
            } catch (\Throwable $e) {
                Log::error('Failed to generate pix renewal charge', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Generated {$generated} pix renewal charge(s).");

        return self::SUCCESS;
    }
}
