<?php

namespace App\Console\Commands\Numbers;

use App\Enums\Notification\NotificationType;
use App\Models\VirtualNumber;
use App\Services\Billing\BillingNotifier;
use App\Services\Credits\CreditService;
use App\Services\VirtualNumbers\ApiwayNumbersConfig;
use App\Services\VirtualNumbers\VirtualNumberService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Charge the next month of every rented number — and cancel the ones nobody can
 * pay for, before API Way bills the platform for them.
 *
 * There is no upstream renew call: an API Way number renews itself and charges
 * the platform on `renews_at`. That single fact is what this command is shaped
 * around. It means the deadline is real and one-sided — miss it and the platform
 * has paid for a month the customer did not — so the last thing this does before
 * the date is delete the number.
 *
 * Two windows, like the API Way instance renewal, and for the same reasons.
 * Warnings start a week out, because the only remedy is a person noticing and
 * topping up. Charges start three days out, because taking the money earlier
 * than necessary is not ours to do.
 */
class RenewVirtualNumbers extends Command
{
    protected $signature = 'numbers:renew
                            {--days-before=3 : Charge renewals due within this many days}
                            {--warn-days-before=7 : Warn about an insufficient balance this far ahead}
                            {--cancel-hours=24 : Cancel an unpaid number this close to its renewal}';

    protected $description = 'Charge rented virtual numbers to the prepaid balance, and cancel the unpayable ones';

    public function handle(VirtualNumberService $numbers, BillingNotifier $notifier, CreditService $credits): int
    {
        Heartbeat::ping('numbers:renew');

        if (! ApiwayNumbersConfig::isConfigured()) {
            $this->warn('API Way numbers credentials are not configured — nothing to do.');

            return self::SUCCESS;
        }

        $chargeThreshold = now()->addDays((int) $this->option('days-before'));
        $warnThreshold = now()->addDays(max(
            (int) $this->option('days-before'),
            (int) $this->option('warn-days-before'),
        ));
        $cancelThreshold = now()->addHours((int) $this->option('cancel-hours'));

        $rows = VirtualNumber::query()
            ->active()
            ->whereNotNull('provider_number_id')
            ->whereNotNull('renews_at')
            ->where('renews_at', '<=', $warnThreshold)
            ->with('tenant')
            ->get();

        $charged = 0;
        $short = 0;
        $cancelled = 0;

        foreach ($rows as $row) {
            // Outside the charge window: say something if the balance is
            // visibly short, but do not take the money yet.
            if ($row->renews_at->gt($chargeThreshold)) {
                if (! $credits->canAfford($row->tenant, $row->price_cents)) {
                    $this->warnShort($notifier, $row);
                    $short++;
                }

                continue;
            }

            try {
                $paid = $numbers->chargeRenewal($row);
            } catch (\Throwable $e) {
                Log::error('Failed to charge a virtual number renewal', [
                    'virtual_number_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($paid) {
                $charged++;

                continue;
            }

            // Out of money and out of time. Cancelling is the only thing that
            // stops the platform paying for another month of a number nobody
            // has paid for — and it has to happen before the renewal, not after.
            if ($row->renews_at->lte($cancelThreshold)) {
                try {
                    $numbers->cancel($row->fresh(), 'no_credit');
                    $cancelled++;

                    $notifier->notifyTenant(NotificationType::VirtualNumberCancelledNoCredit, $row->tenant, [
                        'msisdn' => $row->msisdn,
                    ]);
                } catch (\Throwable $e) {
                    // Loud: a cancel that did not happen is a bill that will.
                    Log::error('Could not cancel an unpaid virtual number', [
                        'virtual_number_id' => $row->id,
                        'provider_number_id' => $row->provider_number_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                continue;
            }

            $this->warnShort($notifier, $row->fresh());
            $short++;
        }

        $this->info("Charged: {$charged}, short of credit: {$short}, cancelled: {$cancelled}.");

        return self::SUCCESS;
    }

    /**
     * Tell the tenant their balance will not cover the renewal.
     *
     * Repeated daily inside the window, not sent once. This is the only warning
     * there is, and what follows it is the number being deleted — one message a
     * week earlier, read while somebody was away, is not a warning.
     */
    private function warnShort(BillingNotifier $notifier, VirtualNumber $row): void
    {
        if ($row->renewal_reminder_sent_at?->isToday()) {
            return;
        }

        $notifier->notifyTenant(NotificationType::VirtualNumberRenewalNoCredit, $row->tenant, [
            'msisdn' => $row->msisdn,
            'due_date' => $row->renews_at->format('d/m/Y'),
            'amount' => 'R$ '.number_format($row->price_cents / 100, 2, ',', '.'),
        ]);

        $row->forceFill(['renewal_reminder_sent_at' => now()])->save();
    }
}
