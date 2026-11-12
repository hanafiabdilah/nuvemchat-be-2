<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Exceptions\ApiwayPartnerException;
use App\Http\Controllers\Controller;
use App\Models\ApiwaySubscription;
use App\Models\AuditLog;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Http\Request;

class AdminApiwayController extends Controller
{
    public function __construct(
        private readonly ApiwayPartnerClient $partner,
        private readonly ApiwayService $apiway,
    ) {}

    /**
     * Live ProxyBR partner catalog. Doubles as the "test connection" probe for
     * the Back Office Integrations tab: a 200 proves the partner token works.
     */
    public function catalog()
    {
        if (! $this->partner->isConfigured()) {
            return response()->json([
                'message' => 'ProxyBR partner token is not configured.',
                'code' => 'apiway_unconfigured',
            ], 503);
        }

        try {
            return response()->json(['data' => $this->partner->plans()]);
        } catch (ApiwayPartnerException $e) {
            $upstream = $e->getHttpStatus();
            $rejectedUs = in_array($upstream, [401, 403], true);

            // ProxyBR's status describes OUR partner token, never the admin
            // holding this session — so it must not be relayed verbatim.
            // Passing its 401 through logged the admin straight out of the
            // Back Office, on the one button whose entire job is to report
            // that the token is wrong, and its bare "Unauthenticated." read
            // like the session had expired. Anything upstream refuses is a
            // 502 here: nothing the caller did caused it.
            // ⚠️ getRawMessage(), not getMessage(): everywhere else the
            // partner's own words are replaced with copy a tenant can act on,
            // but this button exists so a platform operator can see exactly
            // what ProxyBR said. Translating here would leave the one person
            // who can fix the token with nothing to go on.
            return response()->json([
                'message' => $rejectedUs
                    ? "ProxyBR rejected our partner token: {$e->getRawMessage()}"
                    : $e->getRawMessage(),
                'code' => $e->getErrorCode()
                    ?? ($rejectedUs ? 'apiway_unauthorized' : 'apiway_unavailable'),
                'upstream_status' => $upstream,
            ], in_array($upstream, [400, 422], true) ? 422 : 502);
        }
    }

    /**
     * Every API Way instance, across all workspaces — one row per instance.
     *
     * The rows are subscriptions, and that is the same thing: every instance
     * is made on a subscription of its own (quantity 1), so a row is one
     * number, one ProxyBR subscription and at most one connection. Nothing on
     * this page is money any more — instances are free since Nov 2026 — so
     * prices are left out of the response, not just off the screen.
     */
    public function subscriptions(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'max:30'],
            // linked = serves a connection; spare = live but serving nothing
            // (its connection was deleted — left to expire, not renewed).
            'usage' => ['sometimes', 'in:linked,spare'],
            'attention' => ['sometimes', 'boolean'],
            // Live instances that will NOT renew this week: spare, or on a
            // plan that is not usable. ProxyBR revokes at expiry, no grace.
            'lapsing' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $subscriptions = ApiwaySubscription::query()
            ->with([
                'instances:id,apiway_subscription_id,provider_instance_id,name,status,ip_address,connection_id',
                'instances.connection:id,name,status,credentials',
                'tenant.user:id,name,email',
                'tenant.currentSubscription',
            ])
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(($validated['usage'] ?? null) === 'linked', fn ($q) => $q
                ->whereHas('instances', fn ($q) => $q->whereNotNull('connection_id')))
            ->when(($validated['usage'] ?? null) === 'spare', fn ($q) => $q->live()
                ->whereDoesntHave('instances', fn ($q) => $q->whereNotNull('connection_id')))
            ->when($validated['attention'] ?? false, fn ($q) => $this->needingAttention($q))
            ->when($validated['lapsing'] ?? false, fn ($q) => $q->live()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(7)))
            ->when(trim((string) ($validated['search'] ?? '')) !== '', function ($q) use ($validated) {
                $term = trim((string) $validated['search']);
                $like = '%'.$term.'%';

                $q->where(fn ($q) => $q
                    ->when(ctype_digit($term), fn ($q) => $q->orWhere('id', (int) $term)->orWhere('tenant_id', (int) $term))
                    ->orWhere('provider_subscription_id', 'like', $like)
                    ->orWhere('external_ref', 'like', $like)
                    // Grouped: a bare orWhere inside whereHas escapes the
                    // relation's own key constraint and matches every row.
                    ->orWhereHas('instances', fn ($q) => $q->where(fn ($q) => $q
                        ->where('provider_instance_id', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhereHas('connection', fn ($q) => $q->where('name', 'like', $like))))
                    ->orWhereHas('tenant.user', fn ($q) => $q->where(fn ($q) => $q
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like))));
            })
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        $collection = $subscriptions->getCollection()->map(fn (ApiwaySubscription $row) => $this->decorate($row));

        // "Lapsing" is only answerable per row (it reads the plan), so the
        // window above narrows it and this keeps only the ones that will not
        // renew. A page may come back shorter than per_page; that is fine for
        // a list an operator works through a handful at a time.
        if ($validated['lapsing'] ?? false) {
            $collection = $collection->filter(fn (array $row) => ! $row['renews'])->values();
        }

        $subscriptions->setCollection($collection);

        return response()->json($subscriptions);
    }

    /**
     * What an operator wants to know at a glance: how many numbers are running,
     * how many are spare, what is being made right now, what went wrong, and
     * what lapses this week without renewing.
     */
    public function summary()
    {
        $live = ApiwaySubscription::query()->live();

        $linked = (clone $live)
            ->whereHas('instances', fn ($q) => $q->whereNotNull('connection_id'))
            ->count();

        $lapsing = (clone $live)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->with(['instances:id,apiway_subscription_id,connection_id', 'tenant.currentSubscription'])
            ->get()
            ->reject(fn (ApiwaySubscription $row) => $this->renews($row))
            ->count();

        return response()->json(['data' => [
            'configured' => $this->partner->isConfigured(),
            'live_instances' => (clone $live)->count(),
            'linked_instances' => $linked,
            'spare_instances' => (clone $live)->count() - $linked,
            'preparing_count' => ApiwaySubscription::query()
                ->where('status', ApiwaySubscriptionStatus::Provisioning->value)
                ->whereNull('provider_subscription_id')
                ->count(),
            'attention_count' => $this->needingAttention(ApiwaySubscription::query())->count(),
            'lapsing_count' => $lapsing,
            'tenant_count' => (clone $live)->distinct()->count('tenant_id'),
        ]]);
    }

    /** Re-read this workspace's instances from ProxyBR (status, expiry, IP). */
    public function sync(ApiwaySubscription $subscription)
    {
        $this->apiway->syncStatuses($subscription->tenant);

        AuditLog::record(
            'apiway.instance.synced',
            "Synced API Way instance #{$subscription->id} with ProxyBR",
            ['apiway_subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id],
        );

        return response()->json(['data' => $this->decorate($subscription->fresh())]);
    }

    /** Try again an instance that never finished being made. */
    public function retry(ApiwaySubscription $subscription)
    {
        $row = $this->apiway->retryProvision($subscription);

        AuditLog::record(
            'apiway.instance.retried',
            "Retried provisioning of API Way instance #{$subscription->id}",
            ['apiway_subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id],
        );

        return response()->json(['data' => $this->decorate($row)]);
    }

    /**
     * Renew now, for free, instead of waiting for apiway:renew — for the day a
     * renewal failed at ProxyBR and the expiry is close.
     */
    public function renew(ApiwaySubscription $subscription)
    {
        if (! $subscription->provider_subscription_id || ! $subscription->status->isLive()) {
            return response()->json([
                'message' => 'Only a live instance that exists at ProxyBR can be renewed.',
                'code' => 'not_renewable',
            ], 422);
        }

        try {
            $this->apiway->renew(
                $subscription,
                'pingly-admin-renew-'.$subscription->id.'-'.($subscription->expires_at?->format('Ymd') ?? 'none'),
            );
        } catch (ApiwayPartnerException $e) {
            // The operator is the one person who needs ProxyBR's own words.
            return response()->json([
                'message' => $e->getRawMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], in_array($e->getHttpStatus(), [400, 422], true) ? 422 : 502);
        }

        AuditLog::record(
            'apiway.instance.renewed',
            "Renewed API Way instance #{$subscription->id} by hand",
            ['apiway_subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id],
        );

        return response()->json(['data' => $this->decorate($subscription->fresh())]);
    }

    /**
     * Cancel = permanent revoke at ProxyBR. A connection running on it is
     * released and goes inactive; connecting it again makes a new instance.
     */
    public function cancel(ApiwaySubscription $subscription)
    {
        try {
            $row = $this->apiway->cancel($subscription);
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => $e->getRawMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], in_array($e->getHttpStatus(), [400, 422], true) ? 422 : 502);
        }

        AuditLog::record(
            'apiway.instance.cancelled',
            "Cancelled API Way instance #{$subscription->id}",
            ['apiway_subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id],
        );

        return response()->json(['data' => $this->decorate($row)]);
    }

    /**
     * Rows an operator should look at: held at ProxyBR's platform cap, or
     * refused in the last day. (Refunds owed used to be here too; nothing is
     * charged any more, and production had none left when that went.)
     */
    private function needingAttention($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNotNull('meta->capacity_hold')
            ->orWhere(fn ($q) => $q
                ->where('status', ApiwaySubscriptionStatus::Failed->value)
                ->where('updated_at', '>=', now()->subDay())));
    }

    /** Whether apiway:renew will renew this one: it serves a connection on a usable plan. */
    private function renews(ApiwaySubscription $row): bool
    {
        return $row->instances->contains(fn ($instance) => $instance->connection_id !== null)
            && (bool) $row->tenant?->currentSubscription?->isUsable();
    }

    /**
     * One instance, as the Back Office reads it: the row's own state, the
     * instance and the connection it serves, and whether it will renew — with
     * the money columns left out.
     */
    private function decorate(ApiwaySubscription $row): array
    {
        $row->loadMissing([
            'instances.connection:id,name,status,credentials',
            'tenant.user:id,name,email',
            'tenant.currentSubscription',
        ]);

        $meta = $row->meta ?? [];
        $instance = $row->instances->first();
        $connection = $instance?->connection;

        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'tenant_name' => $row->tenant?->user?->name,
            'tenant_email' => $row->tenant?->user?->email,
            'status' => $row->status->value,
            'provider_subscription_id' => $row->provider_subscription_id,
            'external_ref' => $row->external_ref,
            'location_code' => $row->location_code,
            'cycle' => $row->cycle,
            'legacy_purchase' => $row->source === ApiwaySubscriptionSource::Unit,
            'expires_at' => $row->expires_at?->toIso8601String(),
            'last_synced_at' => $row->last_synced_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'renews' => $this->renews($row),
            // More than one only on rows from before one-per-subscription.
            'instance_count' => $row->instances->count(),
            'instance' => $instance ? [
                'id' => $instance->id,
                'provider_instance_id' => $instance->provider_instance_id,
                'name' => $instance->name,
                'status' => $instance->status,
                'ip_address' => $instance->ip_address,
            ] : null,
            'connection' => $connection ? [
                'id' => $connection->id,
                'name' => $connection->name,
                'status' => $connection->status?->value,
                'phone_number' => $connection->credentials['phone_number'] ?? null,
            ] : null,
            'failure' => $meta['failure'] ?? null,
            'capacity_hold' => $meta['capacity_hold'] ?? null,
        ];
    }
}
