<?php

namespace App\Console\Commands\Billing;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Notification\NotificationType;
use App\Exceptions\ApiwayPartnerException;
use App\Jobs\RenewApiwaySubscription;
use App\Models\ApiwaySubscription;
use App\Services\Billing\BillingNotifier;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Credits\CreditService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ProxyBR has NO grace period — subscriptions past expires_at are revoked
 * permanently by their hourly cron. This command runs daily and, a few days
 * ahead of expiry:
 *  - plan_included: renews free at ProxyBR while the tenant's plan is usable;
 *  - unit: charges the renewal to the tenant's prepaid balance, then queues the
 *    partner renew. Too little balance is only ever a warning here — the money
 *    is the customer's to add, and nothing else can be done on their behalf;
 *  - unit (legacy card / open Pix invoice): left alone, so a purchase made
 *    before the balance existed is not charged twice.
 *
 * Two windows, on purpose. Warnings start a week out, because the only remedy
 * is a person noticing and topping up. Charges start three days out, because
 * taking the money earlier than necessary is not ours to do.
 */
class RenewApiwaySubscriptions extends Command
{
    protected $signature = 'apiway:renew
                            {--days-before=3 : Charge renewals expiring within this many days}
                            {--warn-days-before=7 : Warn about an insufficient balance this far ahead}';

    protected $description = 'Renew plan-included API Way subscriptions and charge unit ones to the prepaid balance';

    public function handle(
        BillingNotifier $notifier,
        ApiwayPartnerClient $partner,
        ApiwayService $apiway,
        CreditService $credits,
    ): int {
        Heartbeat::ping('apiway:renew');

        if (! $partner->isConfigured()) {
            $this->warn('ProxyBR partner token not configured — nothing to do.');

            return self::SUCCESS;
        }

        $chargeThreshold = now()->addDays((int) $this->option('days-before'));
        $warnThreshold = now()->addDays(max(
            (int) $this->option('days-before'),
            (int) $this->option('warn-days-before'),
        ));

        $rows = ApiwaySubscription::query()
            ->renewable()
            ->whereNotNull('provider_subscription_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $warnThreshold)
            ->where('expires_at', '>', now())
            ->with('tenant.currentSubscription')
            ->get();

        $renewed = 0;
        $charged = 0;
        $short = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($row->source === ApiwaySubscriptionSource::PlanIncluded) {
                // Free, so there is nothing to warn about and no reason to act
                // before the charge window.
                if ($row->expires_at->gt($chargeThreshold)) {
                    continue;
                }

                // Free renewal rides on the plan: usable (active/grace/manual)
                // keeps renewing; suspended/cancelled stops — ProxyBR will
                // revoke at expiry (apiway:sync mops up afterwards).
                if ($row->tenant?->currentSubscription?->isUsable()) {
                    RenewApiwaySubscription::dispatch(
                        $row->id,
                        'pingly-inc-renew-'.$row->id.'-'.$row->expires_at->format('Ymd'),
                    );
                    $renewed++;
                } else {
                    $this->warnIncludedLapse($notifier, $row);
                    $skipped++;
                }

                continue;
            }

            // Legacy: a unit purchase still riding a MercadoPago preapproval.
            // MercadoPago charges on its own schedule and the webhook renews, so
            // charging the balance too would take the money twice. New purchases
            // never create preapprovals — see ApiwayService::purchaseUnits().
            if ($row->mp_preapproval_id && ! ($row->meta['autopay_off'] ?? false)) {
                continue;
            }

            // Legacy: a Pix renewal invoice issued before the balance existed
            // and still payable. Let the customer pay what they were sent.
            $hasOpenRenewal = $row->invoices()
                ->where('purpose', InvoicePurpose::ApiwayRenewal->value)
                ->where('status', InvoiceStatus::Pending->value)
                ->exists();

            if ($hasOpenRenewal) {
                continue;
            }

            // Still outside the charge window: say something if the balance is
            // visibly short, but do not take the money yet. Priced from the last
            // known total rather than a fresh quote — this is a warning, and a
            // partner call per row per day to sharpen a number the customer will
            // round up anyway is not worth it.
            if ($row->expires_at->gt($chargeThreshold)) {
                if (! $credits->canAfford($row->tenant, $row->total_price_cents)) {
                    $this->warnInsufficientCredit($notifier, $row);
                    $short++;
                }

                continue;
            }

            try {
                $paid = $apiway->renewFromBalance($row);
            } catch (ApiwayPartnerException|\Throwable $e) {
                Log::error('Failed to charge an apiway renewal to the balance', [
                    'apiway_subscription_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($paid) {
                $charged++;

                continue;
            }

            $this->warnInsufficientCredit($notifier, $row->fresh());
            $short++;
        }

        $this->info("Renew jobs: {$renewed}, charged to balance: {$charged}, short of credit: {$short}, skipped (lapsed plan): {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Tell the tenant their balance will not cover the renewal.
     *
     * Repeated on purpose, unlike the other reminders in this file. This is the
     * only warning there is — the Pix invoice that used to sit in the billing
     * page with a due date does not exist any more — and past the expiry ProxyBR
     * revokes the number permanently. One message a week before, missed while
     * somebody was on holiday, is not a warning.
     *
     * Still bounded: once a day at most, and only inside the window the command
     * already acts on.
     */
    private function warnInsufficientCredit(BillingNotifier $notifier, ApiwaySubscription $row): void
    {
        if ($row->renewal_reminder_sent_at?->isToday()) {
            return;
        }

        $notifier->notifyTenant(NotificationType::ApiwayRenewalNoCredit, $row->tenant, [
            'due_date' => $row->expires_at->format('d/m/Y'),
            'amount' => 'R$ '.number_format($row->total_price_cents / 100, 2, ',', '.'),
        ]);

        $row->forceFill(['renewal_reminder_sent_at' => now()])->save();
    }

    /** Warn (once per expiry) that included instances will die with the lapsed plan. */
    private function warnIncludedLapse(BillingNotifier $notifier, ApiwaySubscription $row): void
    {
        if ($row->renewal_reminder_sent_at !== null) {
            return;
        }

        $notifier->notifyTenant(NotificationType::ApiwayRenewalDue, $row->tenant, [
            'due_date' => $row->expires_at->format('d/m/Y'),
            'amount' => 'regularize sua assinatura',
            'quantity' => $row->quantity,
        ]);

        $row->forceFill(['renewal_reminder_sent_at' => now()])->save();
    }
}
