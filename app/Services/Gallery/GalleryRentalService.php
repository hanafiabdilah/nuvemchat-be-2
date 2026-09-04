<?php

namespace App\Services\Gallery;

use App\Enums\Credit\CreditTransactionType;
use App\Enums\Gallery\StorageRentalStatus;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Models\GalleryStorageRental;
use App\Models\Tenant;
use App\Services\Credits\CreditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Renting extra gallery space, month by month, out of the prepaid balance.
 *
 * The whole object turns on one asymmetry: **going up is immediate, going down
 * is not**. An increase is charged pro rata on the spot, because the space is
 * usable the moment it is granted and the customer asked for it. A decrease
 * cannot be applied today without either taking back a month already paid for
 * or refunding it — and this balance refunds nothing except purchases the
 * platform failed to deliver, which a customer changing their mind is not. So a
 * reduction is scheduled: `pending_gb` says what the rental becomes at the next
 * renewal, and until then the customer keeps what they bought.
 *
 * Cancelling is the same operation with a target of zero, for the same reason.
 * It never deletes a file — see GalleryStorage::summary().
 */
class GalleryRentalService
{
    public function __construct(
        private readonly CreditService $credits,
    ) {}

    /**
     * What changing to $gb would cost right now, without changing anything.
     *
     * Exists so the dialog can state the amount before the button rather than
     * after the commit — the same rule the API Way purchase modal follows.
     *
     * @return array{gb: int, current_gb: int, price_per_gb_cents: int, monthly_cents: int, charge_now_cents: int, prorated: bool, scheduled: bool, effective_at: string|null}
     */
    public function quote(Tenant $tenant, int $gb): array
    {
        $gb = max(0, $gb);
        $rental = $this->activeRental($tenant);
        $price = GalleryPricing::pricePerGbCents();
        $currentGb = $rental?->gb ?? 0;

        // Nothing live: the first month is charged in full, from today.
        if ($rental === null) {
            return [
                'gb' => $gb,
                'current_gb' => 0,
                'price_per_gb_cents' => $price,
                'monthly_cents' => $gb * $price,
                'charge_now_cents' => $gb * $price,
                'prorated' => false,
                'scheduled' => false,
                'effective_at' => null,
            ];
        }

        if ($gb > $currentGb) {
            return [
                'gb' => $gb,
                'current_gb' => $currentGb,
                'price_per_gb_cents' => $price,
                'monthly_cents' => $gb * $price,
                'charge_now_cents' => $this->prorataCents($rental, $gb - $currentGb, $price),
                'prorated' => true,
                'scheduled' => false,
                'effective_at' => null,
            ];
        }

        // Same size or smaller: nothing to charge today.
        return [
            'gb' => $gb,
            'current_gb' => $currentGb,
            'price_per_gb_cents' => $rental->price_per_gb_cents,
            'monthly_cents' => $gb * $rental->price_per_gb_cents,
            'charge_now_cents' => 0,
            'prorated' => false,
            'scheduled' => $gb < $currentGb,
            'effective_at' => $gb < $currentGb ? $rental->renews_at?->toISOString() : null,
        ];
    }

    /**
     * Set the rented amount to $gb, charging whatever that costs today.
     *
     * @throws InsufficientCreditException when the balance will not cover the charge
     */
    public function setAmount(Tenant $tenant, int $gb): ?GalleryStorageRental
    {
        $gb = max(0, $gb);
        $rental = $this->activeRental($tenant);

        if ($rental === null) {
            return $gb === 0 ? null : $this->start($tenant, $gb);
        }

        if ($gb > $rental->gb) {
            return $this->increase($tenant, $rental, $gb);
        }

        if ($gb < $rental->gb) {
            // Scheduled, not applied. The month is paid for.
            $rental->update(['pending_gb' => $gb]);

            return $rental->fresh();
        }

        // Asked for exactly what they already have: read as undoing a
        // scheduled reduction, which is the only thing this can mean from a
        // screen that shows the current size in the field.
        $rental->update(['pending_gb' => null]);

        return $rental->fresh();
    }

    /**
     * Stop renting at the end of the paid month.
     *
     * Deliberately not immediate: cancelling on day two of a paid cycle would
     * take away space the customer owns. The one caller that does end it on the
     * spot is the renewal pass, through `end()`, and only because there is no
     * paid month left to protect.
     */
    public function cancel(Tenant $tenant): ?GalleryStorageRental
    {
        $rental = $this->activeRental($tenant);

        if ($rental === null) {
            return null;
        }

        $rental->update(['pending_gb' => 0]);

        return $rental->fresh();
    }

    /**
     * Charge the coming month, applying any scheduled change first.
     *
     * Returns false when the balance did not cover it — the caller decides
     * whether that is a warning (still days to go) or the end of the rental.
     */
    public function chargeRenewal(GalleryStorageRental $rental): bool
    {
        $tenant = $rental->tenant;

        if ($tenant === null) {
            return false;
        }

        // The scheduled change lands here, before the price is worked out: a
        // reduction the customer asked for last month must not be charged at
        // last month's size.
        $gb = $rental->effectiveGbAtRenewal();

        if ($gb <= 0) {
            $this->end($rental, 'requested');

            return true;
        }

        // Repriced at every cycle rather than frozen at signup: this is a
        // month-to-month rental of a platform resource, and a price that could
        // never move would make the first customer's rate permanent. The row
        // keeps the number it was actually charged at.
        $price = GalleryPricing::pricePerGbCents();
        $amount = $gb * $price;
        $due = CarbonImmutable::instance($rental->renews_at ?? now());

        try {
            // A null return means the cycle was already charged by an earlier
            // pass. Reported as success, because it is: the month is paid.
            $this->credits->debit(
                $tenant,
                $amount,
                CreditTransactionType::Renewal,
                self::renewalReference($rental, $due),
                "Renovação de armazenamento da galeria — {$gb} GB",
                ['gallery_storage_rental_id' => $rental->id, 'gb' => $gb, 'renews_at' => $due->toDateString()],
            );
        } catch (InsufficientCreditException) {
            return false;
        }

        $next = $due->addMonth();

        // A cycle that is still in the past means the pass did not run for a
        // while. Catching up from today rather than replaying every missed
        // month is deliberate: those months were never warned about and never
        // charged, and billing three of them in three days would be the
        // customer paying for our scheduler being down. The platform ate the
        // storage; it does not get to bill for it afterwards.
        if ($next->isPast()) {
            $next = CarbonImmutable::now()->addMonth();
        }

        $rental->update([
            'gb' => $gb,
            'pending_gb' => null,
            'price_per_gb_cents' => $price,
            'renews_at' => $next,
            'renewal_reminder_sent_at' => null,
        ]);

        return true;
    }

    /**
     * End the rental now. The allowance goes; not one byte does.
     *
     * Called by the renewal pass when the month could not be paid for, and by
     * `chargeRenewal` when the customer scheduled a cancellation. Both are the
     * boundary of a cycle, which is the only moment ending a rental takes
     * nothing away that was paid for.
     */
    public function end(GalleryStorageRental $rental, string $reason): GalleryStorageRental
    {
        $meta = $rental->meta ?? [];
        $meta['cancel_reason'] = $reason;
        $meta['cancelled_gb'] = $rental->gb;

        $rental->update([
            'status' => StorageRentalStatus::Cancelled,
            'pending_gb' => null,
            'cancelled_at' => now(),
            'meta' => $meta,
        ]);

        return $rental->fresh();
    }

    // --- internals ---------------------------------------------------------

    /** The tenant's rental if it is still granting space, else null. */
    private function activeRental(Tenant $tenant): ?GalleryStorageRental
    {
        $rental = GalleryStorageRental::where('tenant_id', $tenant->id)->first();

        return $rental !== null && $rental->status === StorageRentalStatus::Active && $rental->gb > 0
            ? $rental
            : null;
    }

    private function start(Tenant $tenant, int $gb): GalleryStorageRental
    {
        $price = GalleryPricing::pricePerGbCents();

        // Created before it is paid for, then charged, then activated. The row
        // has to exist for the charge to have a reference, and a row sitting at
        // `cancelled` grants nothing — so a failed charge leaves a workspace
        // exactly where it started rather than with free space.
        $rental = DB::transaction(fn () => GalleryStorageRental::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'gb' => 0,
                'pending_gb' => null,
                'price_per_gb_cents' => $price,
                'status' => StorageRentalStatus::Cancelled,
                'cancelled_at' => now(),
            ],
        ));

        $this->charge($tenant, $rental, $gb * $price, "Armazenamento da galeria — {$gb} GB", ['gb' => $gb]);

        $rental->update([
            'gb' => $gb,
            'price_per_gb_cents' => $price,
            'status' => StorageRentalStatus::Active,
            'started_at' => now(),
            'renews_at' => now()->addMonth(),
            'cancelled_at' => null,
            'renewal_reminder_sent_at' => null,
        ]);

        return $rental->fresh();
    }

    private function increase(Tenant $tenant, GalleryStorageRental $rental, int $gb): GalleryStorageRental
    {
        $added = $gb - $rental->gb;
        $price = GalleryPricing::pricePerGbCents();
        $amount = $this->prorataCents($rental, $added, $price);

        if ($amount > 0) {
            $this->charge(
                $tenant,
                $rental,
                $amount,
                "Armazenamento adicional da galeria — +{$added} GB",
                ['gb' => $gb, 'added_gb' => $added, 'prorated' => true],
            );
        }

        $rental->update([
            'gb' => $gb,
            // An increase clears a pending reduction: asking for more space is
            // not something to do while a shrink nobody remembers is queued.
            'pending_gb' => null,
            'price_per_gb_cents' => $price,
        ]);

        return $rental->fresh();
    }

    /**
     * What the added gigabytes cost for the part of the cycle that is left.
     *
     * Measured in seconds against the cycle the rental is actually in, rather
     * than in whole days against a nominal 30: months are not the same length,
     * and a customer who adds space the day before renewal should pay for a
     * day, not a month.
     */
    private function prorataCents(GalleryStorageRental $rental, int $addedGb, int $pricePerGb): int
    {
        if ($addedGb <= 0) {
            return 0;
        }

        $renewsAt = $rental->renews_at;

        if ($renewsAt === null || $renewsAt->isPast()) {
            // No cycle to prorate against (a rental the renewal pass has not
            // caught up with). Charging the full month is the honest fallback:
            // the next renewal is a month out from here either way.
            return $addedGb * $pricePerGb;
        }

        $cycleSeconds = max(1, $renewsAt->copy()->subMonth()->diffInSeconds($renewsAt));
        $remaining = max(0, now()->diffInSeconds($renewsAt, false));
        $fraction = min(1.0, $remaining / $cycleSeconds);

        // Rounded before the ceiling: 0.1 + 0.2 arithmetic on a price is how a
        // cent nobody can account for gets added to every sale.
        return (int) ceil(round($addedGb * $pricePerGb * $fraction, 4));
    }

    /**
     * @throws InsufficientCreditException
     */
    private function charge(Tenant $tenant, GalleryStorageRental $rental, int $amountCents, string $description, array $meta): void
    {
        if ($amountCents <= 0) {
            return;
        }

        // A numbered reference, not a stable one: a workspace can go 10 → 20 →
        // 10 → 20 inside a single cycle, and a reference naming only the sizes
        // would let the second upgrade look like a duplicate of the first and
        // be swallowed — an upgrade nobody paid for.
        $seq = (int) ($rental->meta['charge_seq'] ?? 0) + 1;

        $this->credits->debit(
            $tenant,
            $amountCents,
            CreditTransactionType::Purchase,
            "gallery:buy:{$rental->id}:{$seq}",
            $description,
            $meta + ['gallery_storage_rental_id' => $rental->id],
        );

        $rental->update(['meta' => array_merge($rental->meta ?? [], ['charge_seq' => $seq])]);
    }

    /** One charge per cycle, so a pass that runs twice in a day cannot bill twice. */
    public static function renewalReference(GalleryStorageRental $rental, ?CarbonImmutable $due = null): string
    {
        $date = ($due ?? CarbonImmutable::instance($rental->renews_at ?? now()))->toDateString();

        return "gallery:renew:{$rental->id}:{$date}";
    }
}
