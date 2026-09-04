<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Conversation\Type;
use App\Enums\Gallery\StorageRentalStatus;
use App\Enums\Message\AttachmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GalleryAsset;
use App\Models\GalleryStorageRental;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Gallery\GalleryPricing;
use App\Services\Gallery\GalleryStorage;
use App\Services\Media\MediaRetention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Back Office: how much disk each customer is using, and how much of it the
 * retention sweep is actually reclaiming.
 *
 * Media is the one cost in this product that only ever rises — every inbound
 * photo lands on our disk whether anyone opens it or not — and `media:purge`
 * turned that into something somebody has to manage. Managing it needs a
 * number, and there wasn't one.
 *
 * Sizes come from `messages.attachment_size`, written when the file lands.
 * Rows stored before that column existed read null until `media:backfill-sizes`
 * reaches them, so every figure here ships with `unmeasured` next to it: a
 * total that silently covers half the files is worse than an honest partial.
 */
class AdminStorageController extends Controller
{
    private const TOP_N = 25;

    public function index(Request $request)
    {
        $tenantId = $request->integer('tenant_id') ?: null;

        // Messages have no tenant_id — they hang off conversations, which hang
        // off connections. One join, reused by every aggregate below.
        $base = fn () => Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('connections', 'conversations.connection_id', '=', 'connections.id')
            ->when($tenantId, fn ($q) => $q->where('connections.tenant_id', $tenantId));

        $stored = fn () => $base()
            ->whereNotNull('messages.attachment')
            ->where(fn ($q) => $q
                ->whereNull('messages.attachment_status')
                ->orWhere('messages.attachment_status', '!=', AttachmentStatus::Expired->value));

        return response()->json([
            'data' => [
                'totals' => $this->totals($stored()),
                'by_tenant' => $tenantId ? [] : $this->byTenant($stored()),
                'by_channel' => $this->byChannel($stored()),
                'retention' => $this->retention($base()),
                'gallery' => $this->gallery($tenantId),
            ],
        ]);
    }

    /**
     * The other half of the disk bill, and the only half anyone is paying for.
     *
     * Message media above is a cost the platform absorbs and `media:purge`
     * bounds. Gallery bytes are sold: a workspace keeps them for as long as it
     * wants and pays per gigabyte-month past what its plan grants. Reported
     * side by side because they share a disk, and an operator watching that
     * disk fill up needs to know which half is growing — one of them can be
     * priced, and the other can only be waited out.
     *
     * Sizes here are exact. Unlike `messages.attachment_size` there is no
     * backfill to wait for: a gallery asset cannot be created without its size,
     * because the size is what the quota check is made of.
     */
    private function gallery(?int $tenantId): array
    {
        $assets = GalleryAsset::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        $totals = (clone $assets)
            ->selectRaw('COUNT(*) as files, SUM(size_bytes) as bytes, COUNT(DISTINCT tenant_id) as tenants')
            ->first();

        $rentals = GalleryStorageRental::query()
            ->where('status', StorageRentalStatus::Active->value)
            ->where('gb', '>', 0)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw('COUNT(*) as rentals, SUM(gb) as gb, SUM(gb * price_per_gb_cents) as monthly_cents')
            ->first();

        return [
            'files' => (int) ($totals->files ?? 0),
            'bytes' => (int) ($totals->bytes ?? 0),
            'tenants' => (int) ($totals->tenants ?? 0),
            'rented_gb' => (int) ($rentals->gb ?? 0),
            'rentals' => (int) ($rentals->rentals ?? 0),
            // What the rented space bills every month at the price each row was
            // last charged at — not at today's list price, which is what the
            // platform *would* charge and not what it *is* charging.
            'monthly_revenue_cents' => (int) ($rentals->monthly_cents ?? 0),
            'pricing' => GalleryPricing::settings(),
            'by_tenant' => $tenantId ? [] : $this->galleryByTenant(),
        ];
    }

    /** The workspaces holding the most library bytes, with what they pay for it. */
    private function galleryByTenant(): array
    {
        $rows = GalleryAsset::query()
            ->selectRaw('tenant_id, COUNT(*) as files, SUM(size_bytes) as bytes')
            ->groupBy('tenant_id')
            ->orderByDesc('bytes')
            ->limit(self::TOP_N)
            ->get();

        $tenants = Tenant::with('user:id,name,email')
            ->whereIn('id', $rows->pluck('tenant_id'))
            ->get()
            ->keyBy('id');

        $rentals = GalleryStorageRental::whereIn('tenant_id', $rows->pluck('tenant_id'))
            ->get()
            ->keyBy('tenant_id');

        $storage = app(GalleryStorage::class);

        return $rows->map(function ($r) use ($tenants, $rentals, $storage) {
            $tenant = $tenants[$r->tenant_id] ?? null;
            $rental = $rentals[$r->tenant_id] ?? null;

            return [
                'tenant_id' => (int) $r->tenant_id,
                'name' => $tenant?->user?->name ?? "Tenant #{$r->tenant_id}",
                'email' => $tenant?->user?->email,
                'files' => (int) $r->files,
                'bytes' => (int) $r->bytes,
                // Both halves of the allowance, so a row over its limit can be
                // told apart from one that simply bought a lot of space.
                'plan_gb' => $tenant ? $storage->planGb($tenant) : 0,
                'rented_gb' => $rental?->status === StorageRentalStatus::Active ? (int) $rental->gb : 0,
                'monthly_cents' => $rental?->status === StorageRentalStatus::Active ? $rental->monthlyCents() : 0,
            ];
        })->values()->all();
    }

    /**
     * Set what a rented gigabyte-month costs, and the bounds on how many can be
     * rented from the dashboard.
     *
     * Lives next to the storage figures rather than with the other commercial
     * settings because the price of a gigabyte is only meaningful beside the
     * count of gigabytes — and because that is the screen an operator is on
     * when the number stops looking right.
     */
    public function updatePricing(Request $request)
    {
        $validated = $request->validate([
            'price_per_gb_cents' => ['required', 'integer', 'min:1', 'max:1000000'],
            'min_rent_gb' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_rent_gb' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        if ($validated['max_rent_gb'] < $validated['min_rent_gb']) {
            return response()->json([
                'message' => 'The maximum must not be below the minimum.',
                'errors' => ['max_rent_gb' => ['The maximum must not be below the minimum.']],
            ], 422);
        }

        GalleryPricing::store($validated);

        AuditLog::record(
            'gallery.pricing.update',
            sprintf(
                'Gallery storage priced at %d cents/GB (rentable %d–%d GB)',
                $validated['price_per_gb_cents'],
                $validated['min_rent_gb'],
                $validated['max_rent_gb'],
            ),
            $validated,
        );

        return response()->json(['data' => GalleryPricing::settings()]);
    }

    private function totals($query): array
    {
        $row = (clone $query)->selectRaw(
            'COUNT(*) as files,
             SUM(COALESCE(messages.attachment_size, 0)) as bytes,
             SUM(CASE WHEN messages.attachment_size IS NULL THEN 1 ELSE 0 END) as unmeasured'
        )->first();

        $files = (int) ($row->files ?? 0);
        $unmeasured = (int) ($row->unmeasured ?? 0);

        return [
            'files' => $files,
            'bytes' => (int) ($row->bytes ?? 0),
            'unmeasured' => $unmeasured,
            // The reader's cue for how much to trust `bytes`. 100% coverage
            // means the backfill has caught up.
            'measured_pct' => $files > 0 ? round((($files - $unmeasured) / $files) * 100, 1) : 100.0,
        ];
    }

    private function byTenant($query): array
    {
        $rows = (clone $query)
            ->selectRaw(
                'connections.tenant_id as tenant_id,
                 COUNT(*) as files,
                 SUM(COALESCE(messages.attachment_size, 0)) as bytes,
                 SUM(CASE WHEN messages.attachment_size IS NULL THEN 1 ELSE 0 END) as unmeasured'
            )
            ->groupBy('connections.tenant_id')
            ->orderByDesc('bytes')
            ->limit(self::TOP_N)
            ->get();

        $tenants = Tenant::with('user:id,name,email')
            ->whereIn('id', $rows->pluck('tenant_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($r) use ($tenants) {
            $tenant = $tenants[$r->tenant_id] ?? null;

            return [
                'tenant_id' => (int) $r->tenant_id,
                'name' => $tenant?->user?->name ?? "Tenant #{$r->tenant_id}",
                'email' => $tenant?->user?->email,
                'files' => (int) $r->files,
                'bytes' => (int) $r->bytes,
                'unmeasured' => (int) $r->unmeasured,
            ];
        })->values()->all();
    }

    private function byChannel($query): array
    {
        return (clone $query)
            ->selectRaw('connections.channel as channel, COUNT(*) as files, SUM(COALESCE(messages.attachment_size, 0)) as bytes')
            ->groupBy('connections.channel')
            ->orderByDesc('bytes')
            ->get()
            ->map(fn ($r) => [
                'channel' => $r->channel instanceof \BackedEnum ? $r->channel->value : $r->channel,
                'files' => (int) $r->files,
                'bytes' => (int) $r->bytes,
            ])->values()->all();
    }

    /**
     * Whether retention is doing its job: what has already been reclaimed, and
     * what is sitting past its deadline waiting for the next sweep. A backlog
     * that keeps growing means `media:purge` is not running — which the health
     * page will also say, from the other direction.
     */
    private function retention($base): array
    {
        $groupDays = MediaRetention::daysFor(Type::Group);
        $privateDays = MediaRetention::daysFor(Type::Private);

        $expired = (clone $base)
            ->where('messages.attachment_status', AttachmentStatus::Expired->value)
            ->count();

        $overdue = 0;

        // Null days = that window is switched off, so nothing in it is overdue.
        if ($groupDays !== null || $privateDays !== null) {
            $overdue = (clone $base)
                ->whereNotNull('messages.attachment')
                ->where(fn ($q) => $q
                    ->whereNull('messages.attachment_status')
                    ->orWhere('messages.attachment_status', '!=', AttachmentStatus::Expired->value))
                ->where(function ($q) use ($groupDays, $privateDays) {
                    if ($groupDays !== null) {
                        $q->orWhere(fn ($qq) => $qq
                            ->where('conversations.type', Type::Group->value)
                            ->where('messages.created_at', '<', now()->subDays($groupDays)));
                    }

                    if ($privateDays !== null) {
                        $q->orWhere(fn ($qq) => $qq
                            ->where('conversations.type', '!=', Type::Group->value)
                            ->where('messages.created_at', '<', now()->subDays($privateDays)));
                    }
                })
                ->count();
        }

        return [
            'enabled' => MediaRetention::enabled(),
            'group_days' => $groupDays,
            'private_days' => $privateDays,
            'expired_files' => $expired,
            'overdue_files' => $overdue,
        ];
    }
}
