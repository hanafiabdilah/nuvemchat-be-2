<?php

namespace App\Services\Gallery;

use App\Enums\Billing\Addon;
use App\Enums\Billing\Quota;
use App\Enums\Gallery\AssetOrigin;
use App\Enums\Gallery\StorageRentalStatus;
use App\Exceptions\Media\StorageQuotaExceededException;
use App\Models\GalleryAsset;
use App\Models\GalleryStorageRental;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Support\Facades\Cache;

/**
 * How much space a workspace has, how much it is using, and whether one more
 * file fits.
 *
 * Every screen and every write asks this one object, because the answer is
 * arithmetic over two independent sources — what the plan grants and what the
 * tenant rents — and a second copy of that sum is a second chance to disagree
 * with the meter the customer is looking at.
 *
 * ⚠️ Since Nov 2026 every file the gallery lists counts: the library itself,
 * and the uploads it points at — flow, campaign and catalog media, files sent
 * through the API or MCP, attachments agents sent from a conversation, and
 * what the AI made. The meter is the workspace's storage, not "what was saved
 * here on purpose". Every place a person adds a file asks assertCanStore()
 * (or assertCanUpload(), its billing-switched twin) first; AI output is
 * counted and never refused.
 *
 * Still not counted, on purpose: files customers send and widget visitors'
 * uploads. They arrive unbidden and cannot be refused, and counting them would
 * let a stranger fill the space an agent needs to answer. They are never
 * registered in the gallery, so they cannot reach the sum below.
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

    /** Gigabytes bought on top of the plan, as a plan add-on. */
    public function extraGb(Tenant $tenant): int
    {
        return $tenant->currentSubscription?->addonQuantities()[Addon::GalleryStorage->value] ?? 0;
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
     * Bytes the workspace is storing right now — every row the gallery lists.
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
     * Refuse $bytes more when they do not fit.
     *
     * $bytes may be zero or negative — a replace that is not bigger than what
     * it replaces, or a "save to gallery" that swaps a counted row for its
     * copy — and those always pass, even in a workspace already over its
     * limit: nothing grows, so there is nothing to refuse.
     *
     * @throws StorageQuotaExceededException
     */
    public function assertCanStore(Tenant $tenant, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $used = $this->usedBytes($tenant);
        $limit = $this->limitBytes($tenant);

        if ($used + $bytes > $limit) {
            throw new StorageQuotaExceededException($used, $limit, $bytes);
        }
    }

    /**
     * The check every door outside the library asks — `POST /api/uploads`
     * (flows, campaigns, products, the API), MCP, and the attachments an agent
     * sends from a conversation.
     *
     * ⚠️ Honours `services.billing.enforce`, like every other plan limit
     * (feature:, subscription.active, the connection and agent caps): these
     * doors were free until Nov 2026, and an install that runs without billing
     * must not start refusing chat attachments because no plan grants it a
     * gigabyte. The library's own upload keeps asserting unconditionally — it
     * always has, it is the part that is sold.
     *
     * @throws StorageQuotaExceededException
     */
    public function assertCanUpload(Tenant $tenant, int $bytes): void
    {
        if (! config('services.billing.enforce')) {
            return;
        }

        $this->assertCanStore($tenant, $bytes);
    }

    /** Warning thresholds, in percent of the allowance, highest first. */
    public const ALERT_LEVELS = [100, 95, 80];

    /**
     * Which warning a usage sits at: 100 (full), 95, 80, or 0 (none).
     *
     * A workspace with no allowance at all is full the moment it stores one
     * byte, and "nothing stored, nothing granted" is not a warning.
     */
    public static function alertLevel(int $usedBytes, int $limitBytes): int
    {
        if ($limitBytes <= 0) {
            return $usedBytes > 0 ? 100 : 0;
        }

        $percent = $usedBytes * 100 / $limitBytes;

        foreach (self::ALERT_LEVELS as $level) {
            if ($percent >= $level) {
                return $level;
            }
        }

        return 0;
    }

    /**
     * The storage warning for the dashboard banner, or null when there is none.
     *
     * Only where billing is enforced: elsewhere nothing is ever refused, and a
     * banner about a limit that does not apply is noise. Cached for a minute —
     * `GET /api/user` reads it, and a SUM over a busy workspace's files on each
     * of those is more than a banner is worth.
     *
     * @return array{level: int, used_bytes: int, limit_bytes: int}|null
     */
    public function alertFor(Tenant $tenant): ?array
    {
        if (! config('services.billing.enforce')) {
            return null;
        }

        $usage = Cache::remember(
            "storage:usage:{$tenant->id}",
            60,
            fn () => ['used' => $this->usedBytes($tenant), 'limit' => $this->limitBytes($tenant)],
        );

        $level = self::alertLevel($usage['used'], $usage['limit']);

        return $level === 0 ? null : [
            'level' => $level,
            'used_bytes' => $usage['used'],
            'limit_bytes' => $usage['limit'],
        ];
    }

    /**
     * Everything a storage meter needs, in one read.
     *
     * `used` can exceed `limit` and that is a real state, not a bug: a rental
     * that lapsed or a plan that was downgraded takes the allowance away and
     * leaves the files where they are. The library goes read-only; nothing is
     * deleted, here or on any schedule.
     *
     * `files` counts every file the meter charges for; `linked_files` is the
     * part of it that came from somewhere else (flows, campaigns, the catalog,
     * the API, chats, the AI) rather than straight into the library.
     *
     * @return array{plan_gb: int, extra_gb: int, rented_gb: int, limit_bytes: int, used_bytes: int, remaining_bytes: int, files: int, linked_files: int, over_quota: bool, read_only: bool}
     */
    public function summary(Tenant $tenant): array
    {
        $planGb = $this->planGb($tenant);
        $rentedGb = $this->rentedGb($tenant);
        $limit = ($planGb + $rentedGb) * self::BYTES_PER_GB;
        $used = $this->usedBytes($tenant);

        return [
            // The plan's allowance **including** the extra gigabytes bought
            // with it — what the subscription gives. `extra_gb` is that part.
            'plan_gb' => $planGb,
            'extra_gb' => min($this->extraGb($tenant), $planGb),
            'rented_gb' => $rentedGb,
            'limit_bytes' => $limit,
            'used_bytes' => $used,
            'remaining_bytes' => max(0, $limit - $used),
            'files' => GalleryAsset::where('tenant_id', $tenant->id)->count(),
            'linked_files' => GalleryAsset::where('tenant_id', $tenant->id)
                ->where('origin', '!=', AssetOrigin::Gallery->value)
                ->count(),
            'over_quota' => $used > $limit,
            // A workspace with no space at all — no plan allowance and nothing
            // rented — is read-only too, and needs to be told that in the same
            // words as one that filled up. Both end at the same screen.
            'read_only' => $limit <= 0 || $used >= $limit,
        ];
    }
}
