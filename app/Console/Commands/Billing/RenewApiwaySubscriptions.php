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
use App\Services\Billing\BillingService;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ProxyBR has NO grace period — subscriptions past expires_at are revoked
 * permanently by their hourly cron. This command runs daily and, a few days
 * ahead of expiry:
 *  - plan_included: renews free at ProxyBR while the tenant's plan is usable;
 *  - unit (pix, or card with auto-debit off): emits a Pix renewal invoice +
 *    WhatsApp reminder — paying it triggers the partner renew;
 *  - unit (card): the MercadoPago preapproval auto-debits and the webhook
 *    renews; nothing to do here unless auto-debit was turned off.
 */
class RenewApiwaySubscriptions extends Command
{
    protected $signature = 'apiway:renew
                            {--days-before=3 : Act on subscriptions expiring within this many days}';

    protected $description = 'Renew plan-included API Way subscriptions and bill unit ones nearing expiry';

    public function handle(BillingService $billing, BillingNotifier $notifier, ApiwayPartnerClient $partner): int
    {
        if (! $partner->isConfigured()) {
            $this->warn('ProxyBR partner token not configured — nothing to do.');

            return self::SUCCESS;
        }

        $threshold = now()->addDays((int) $this->option('days-before'));

        $rows = ApiwaySubscription::query()
            ->renewable()
            ->whereNotNull('provider_subscription_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $threshold)
            ->where('expires_at', '>', now())
            ->with('tenant.currentSubscription')
            ->get();

        $renewed = 0;
        $invoiced = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($row->source === ApiwaySubscriptionSource::PlanIncluded) {
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

            // Unit with live card auto-debit: MercadoPago charges on its own
            // schedule and the webhook renews. Only fall back to Pix when
            // auto-debit is off/paused.
            if ($row->mp_preapproval_id && ! ($row->meta['autopay_off'] ?? false)) {
                continue;
            }

            $hasOpenRenewal = $row->invoices()
                ->where('purpose', InvoicePurpose::ApiwayRenewal->value)
                ->where('status', InvoiceStatus::Pending->value)
                ->exists();

            if ($hasOpenRenewal) {
                continue;
            }

            try {
                // Renewal price follows the current catalog.
                $quote = $partner->quote($row->quantity, $row->location_code, $row->cycle);
                $row->update([
                    'unit_price_cents' => (int) round(((float) ($quote['unit_price'] ?? 0)) * 100),
                    'total_price_cents' => (int) round(((float) ($quote['total_price'] ?? 0)) * 100),
                ]);

                $billing->createApiwayPixInvoice($row->fresh(), InvoicePurpose::ApiwayRenewal);
                $invoiced++;
            } catch (ApiwayPartnerException|\Throwable $e) {
                Log::error('Failed to generate apiway renewal invoice', [
                    'apiway_subscription_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($row->renewal_reminder_sent_at === null) {
                $notifier->notifyTenant(NotificationType::ApiwayRenewalDue, $row->tenant, [
                    'due_date' => $row->expires_at->format('d/m/Y'),
                    'amount' => 'R$ '.number_format($row->total_price_cents / 100, 2, ',', '.'),
                    'quantity' => $row->quantity,
                ]);
                $row->forceFill(['renewal_reminder_sent_at' => now()])->save();
            }
        }

        $this->info("Renew jobs: {$renewed}, renewal invoices: {$invoiced}, skipped (lapsed plan): {$skipped}.");

        return self::SUCCESS;
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
