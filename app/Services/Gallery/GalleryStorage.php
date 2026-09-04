<?php

namespace App\Services\Gallery;

use App\Enums\Billing\Quota;
use App\Enums\Gallery\StorageRentalStatus;
use App\Models\GalleryAsset;
use App\Models\GalleryStorageRental;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionGate;

/**
 * How much space a workspace has, how much it is using, and whether one more
 * file fits.
 *
 * Every screen and every write asks this one object, because the answer is
 * arithmetic over two independent sources — what the plan grants and what the
 * tenant rents — and a second copy of that sum is a second chance to disagree
 * with the meter the customer is looking at.
 *
 * Only gallery files count. Message attachments live on the same disk and are
 * far larger in aggregate, but they are not the tenant's to manage: they arrive
 * unbidden and `media:purge` deletes them on its own schedule. Charging a
 * customer's library quota for a photo a stranger sent them would make the
 * meter move on its own and the limit impossible to plan against.
 */
class GalleryStorage
{
    /** Binary GB, because that is what a file manager will report back. */
    public const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly SubscriptionGate $gate,
    ) {}

    /**
     * Gigabytes the plan includes.
     *
     * ⚠️ Absent means zero, not unlimited — see Quota::GalleryStorageGb. This
     * is the one place that reading is applied, so getting it wrong here gives
     * every legacy plan an unmetered disk.
     */
    public function planGb(Tenant $tenant): int
    {
        return max(0, $this->gate->quota($tenant, Quota::GalleryStorageGb->value) ?? 0);
    }

    public function rental(Tenant $tenant): ?GalleryStorageRental
    {
        return GalleryStorageRental::where('tenant_id', $tenant->id)->first();
    }

    /** Gigabytes currently rented. A cancelled rental grants nothing. */
    public function rentedGb(Tenant $tenant): int
    {
        $rental = $this->rental($tenant);

        return $rental !== null && $rental->status === StorageRentalStatus::Active
            ? max(0, $rental->gb)
            : 0;
    }

    public function limitBytes(Tenant $tenant): int
    {
        return ($this->planGb($tenant) + $this->rentedGb($tenant)) * self::BYTES_PER_GB;
    }

    /**
     * Bytes the library is holding right now.
     *
     * A live SUM rather than a counter on the tenant row. The number gates a
     * write, so it has to be true at the moment of the write, and a
     * denormalised counter that drifts by one failed upload is a customer who
     * cannot use space they have paid for and no way for support to see why.
     * The index on `tenant_id` keeps it to one range scan.
     */
    public function usedBytes(Tenant $tenant): int
    {
        return (int) GalleryAsset::where('tenant_id', $tenant->id)->sum('size_bytes');
    }

    public function remainingBytes(Tenant $tenant): int
    {
        return max(0, $this->limitBytes($tenant) - $this->usedBytes($tenant));
    }

    /**
     * Whether $bytes more would fit.
     *
     * No overdraft, unlike an AI run: the size is known before the file is
     * stored, so there is no excuse for accepting bytes the workspace has not
     * paid to keep. `canAfford`, not `canSpend` — the same distinction the
     * wallet draws, for the same reason.
     */
    public function canStore(Tenant $tenant, int $bytes): bool
    {
        return $this->usedBytes($tenant) + $bytes <= $this->limitBytes($tenant);
    }

    /**
     * Everything a storage meter needs, in one read.
     *
     * `used` can exceed `limit` and that is a real state, not a bug: a rental
     * that lapsed or a plan that was downgraded takes the allowance away and
     * leaves the files where they are. The library goes read-only; nothing is
     * deleted, here or on any schedule.
     *
     * @return array{plan_gb: int, rented_gb: int, limit_bytes: int, used_bytes: int, remaining_bytes: int, files: int, over_quota: bool, read_only: bool}
     */
    public function summary(Tenant $tenant): array
    {
        $planGb = $this->planGb($tenant);
        $rentedGb = $this->rentedGb($tenant);
        $limit = ($planGb + $rentedGb) * self::BYTES_PER_GB;
        $used = $this->usedBytes($tenant);

        return [
            'plan_gb' => $planGb,
            'rented_gb' => $rentedGb,
            'limit_bytes' => $limit,
            'used_bytes' => $used,
            'remaining_bytes' => max(0, $limit - $used),
            'files' => GalleryAsset::where('tenant_id', $tenant->id)->count(),
            'over_quota' => $used > $limit,
            // A workspace with no space at all — no plan allowance and nothing
            // rented — is read-only too, and needs to be told that in the same
            // words as one that filled up. Both end at the same screen.
            'read_only' => $limit <= 0 || $used >= $limit,
        ];
    }
}
