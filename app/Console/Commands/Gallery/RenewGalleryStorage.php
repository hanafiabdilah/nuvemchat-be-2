<?php

namespace App\Console\Commands\Gallery;

use App\Enums\Notification\NotificationType;
use App\Models\GalleryStorageRental;
use App\Services\Billing\BillingNotifier;
use App\Services\Credits\CreditService;
use App\Services\Gallery\GalleryPricing;
use App\Services\Gallery\GalleryRentalService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Charge the coming month of every rented gigabyte, and end the rentals nobody
 * can pay for.
 *
 * Two windows, the same shape as the API Way and virtual-number passes and for
 * the same reasons: warnings start a week out, because the only remedy is a
 * person noticing and topping up; charges start three days out, because taking
 * the money earlier than necessary is not ours to do.
 *
 * What is different here — and it is the whole reason this command is gentler
 * than the other two — is what happens at the deadline. A missed API Way
 * renewal is a permanently revoked instance; a missed number renewal means the
 * platform pays another month for a number the customer did not. Neither is
 * true of a disk we own. So the deadline takes the *allowance* away and never a
 * file: the library goes read-only, everything already in it stays readable and
 * sendable, and topping up brings the space back. Nothing in this codebase
 * deletes a gallery asset except a person clicking delete.
 */
class RenewGalleryStorage extends Command
{
    protected $signature = 'gallery:renew
                            {--days-before=3 : Charge renewals due within this many days}
                            {--warn-days-before=7 : Warn about an insufficient balance this far ahead}';

    protected $description = 'Charge rented gallery storage to the prepaid balance, and end the unpayable rentals';

    public function handle(GalleryRentalService $rentals, BillingNotifier $notifier, CreditService $credits): int
    {
        Heartbeat::ping('gallery:renew');

        $chargeThreshold = now()->addDays((int) $this->option('days-before'));
        $warnThreshold = now()->addDays(max(
            (int) $this->option('days-before'),
            (int) $this->option('warn-days-before'),
        ));

        $rows = GalleryStorageRental::query()
            ->active()
            ->where('gb', '>', 0)
            ->whereNotNull('renews_at')
            ->where('renews_at', '<=', $warnThreshold)
            ->with('tenant')
            ->get();

        $charged = 0;
        $short = 0;
        $ended = 0;

        foreach ($rows as $rental) {
            if ($rental->tenant === null) {
                continue;
            }

            // The amount that will actually be charged: a scheduled reduction
            // lands at this renewal, so warning at today's size would name a
            // figure the customer already asked us not to charge.
            $gb = $rental->effectiveGbAtRenewal();
            $amount = GalleryPricing::monthlyCents($gb);

            // Outside the charge window: say something if the balance is
            // visibly short, but do not take the money yet.
            if ($rental->renews_at->gt($chargeThreshold)) {
                if ($gb > 0 && ! $credits->canAfford($rental->tenant, $amount)) {
                    $this->warnShort($notifier, $rental, $gb, $amount);
                    $short++;
                }

                continue;
            }

            try {
                $paid = $rentals->chargeRenewal($rental);
            } catch (\Throwable $e) {
                Log::error('Failed to charge a gallery storage renewal', [
                    'gallery_storage_rental_id' => $rental->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($paid) {
                $charged++;

                continue;
            }

            // Out of money. The rental only ends once the paid month is
            // genuinely over — not a day early like a number, because there is
            // no upstream bill racing us and no reason to take space away
            // before the customer has had all of what they bought.
            if ($rental->renews_at->isPast()) {
                $rentals->end($rental, 'no_credit');
                $ended++;

                $notifier->notifyTenant(NotificationType::GalleryStorageCancelledNoCredit, $rental->tenant, [
                    'gb' => $gb,
                ]);

                continue;
            }

            $this->warnShort($notifier, $rental->fresh(), $gb, $amount);
            $short++;
        }

        $this->info("Charged: {$charged}, short of credit: {$short}, ended: {$ended}.");

        return self::SUCCESS;
    }

    /**
     * Tell the tenant their balance will not cover the renewal.
     *
     * Repeated daily inside the window rather than sent once: one message a
     * week earlier, read while somebody was away, is not a warning. The stamp
     * keeps it to one a day.
     */
    private function warnShort(BillingNotifier $notifier, GalleryStorageRental $rental, int $gb, int $amountCents): void
    {
        if ($rental->renewal_reminder_sent_at?->isToday()) {
            return;
        }

        $notifier->notifyTenant(NotificationType::GalleryStorageRenewalNoCredit, $rental->tenant, [
            'gb' => $gb,
            'due_date' => $rental->renews_at->format('d/m/Y'),
            'amount' => 'R$ '.number_format($amountCents / 100, 2, ',', '.'),
        ]);

        $rental->forceFill(['renewal_reminder_sent_at' => now()])->save();
    }
}
