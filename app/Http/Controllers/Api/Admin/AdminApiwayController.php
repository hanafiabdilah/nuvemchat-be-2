<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Exceptions\ApiwayPartnerException;
use App\Http\Controllers\Controller;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\AuditLog;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Services\Money\MarketMoney;
use Illuminate\Http\Request;

class AdminApiwayController extends Controller
{
    public function __construct(private readonly ApiwayPartnerClient $partner) {}

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
     * Cross-tenant list of API Way subscriptions (local mirror of the partner
     * rows — financials live in `invoices` with an apiway purpose).
     */
    public function subscriptions(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'max:30'],
            'source' => ['sometimes', 'string', 'max:30'],
            'attention' => ['sometimes', 'boolean'],
            // Renewal window: ProxyBR has no grace, so "what lapses this week"
            // is the list an operator actually works through.
            'expiring' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $subscriptions = ApiwaySubscription::query()
            ->with(['instances:id,apiway_subscription_id,provider_instance_id,name,status,connection_id', 'tenant.user:id,name,email'])
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($validated['attention'] ?? false, fn ($q) => $q->needsAttention())
            ->when($validated['expiring'] ?? false, fn ($q) => $q->live()
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
                        ->orWhere('name', 'like', $like)))
                    ->orWhereHas('tenant.user', fn ($q) => $q->where(fn ($q) => $q
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like))));
            })
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        $subscriptions->setCollection(
            $subscriptions->getCollection()->map(fn (ApiwaySubscription $row) => $this->decorate($row)),
        );

        return response()->json($subscriptions);
    }

    /**
     * The platform's exposure on API Way, across every workspace: what is
     * live, what lapses this week (ProxyBR revokes at expiry, no grace), what
     * someone is owed, and what the paid units bring in per month.
     *
     * Revenue is kept per currency — rows carry their workspace's money, and
     * reais plus rupiah is not an amount. Plan-included units are counted, not
     * priced: their cost is inside the plan.
     */
    public function summary()
    {
        $live = ApiwaySubscription::query()->live();

        $base = MarketMoney::baseCurrency();
        $monthly = [];

        (clone $live)
            ->where('source', ApiwaySubscriptionSource::Unit->value)
            ->get(['cycle', 'total_price_cents', 'currency'])
            ->each(function (ApiwaySubscription $row) use (&$monthly, $base) {
                $currency = strtoupper($row->currency ?: $base);
                $cents = $row->cycle === 'anual'
                    ? intdiv((int) $row->total_price_cents, 12)
                    : (int) $row->total_price_cents;
                $monthly[$currency] = ($monthly[$currency] ?? 0) + $cents;
            });

        return response()->json(['data' => [
            'configured' => $this->partner->isConfigured(),
            'live_count' => (clone $live)->count(),
            'live_units' => (int) (clone $live)->sum('quantity'),
            'included_units' => (int) (clone $live)
                ->where('source', ApiwaySubscriptionSource::PlanIncluded->value)
                ->sum('quantity'),
            'live_instances' => ApiwayInstance::query()
                ->whereHas('subscription', fn ($q) => $q->live())
                ->count(),
            'linked_instances' => ApiwayInstance::query()
                ->whereNotNull('connection_id')
                ->whereHas('subscription', fn ($q) => $q->live())
                ->count(),
            'expiring_count' => (clone $live)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(7))
                ->count(),
            'provisioning_count' => ApiwaySubscription::query()
                ->where('status', ApiwaySubscriptionStatus::Provisioning->value)
                ->count(),
            'attention_count' => ApiwaySubscription::query()->needsAttention()->count(),
            'tenant_count' => (clone $live)->distinct()->count('tenant_id'),
            'base_currency' => $base,
            'monthly_revenue_cents' => $monthly[$base] ?? 0,
            'revenue_by_currency' => array_diff_key($monthly, [$base => true]),
        ]]);
    }

    /**
     * Record that a flagged refund was actually paid back.
     *
     * Without this the health check below never clears, and an alert that can
     * only go red is an alert operators learn to scroll past. Money moves at
     * the payment service, by hand — this only writes down that it happened.
     */
    public function settleRefund(Request $request, ApiwaySubscription $subscription)
    {
        $meta = $subscription->meta ?? [];

        if (empty($meta['needs_refund'])) {
            return response()->json([
                'message' => 'This subscription is not flagged for a refund.',
                'code' => 'not_flagged',
            ], 422);
        }

        if (! empty($meta['refund_settled_at'])) {
            return response()->json(['data' => $this->decorate($subscription)]);
        }

        $meta['refund_settled_at'] = now()->toISOString();
        $meta['refund_settled_by'] = $request->user()?->name;
        $subscription->update(['meta' => $meta]);

        AuditLog::record(
            'apiway.refund.settled',
            "Marked API Way subscription #{$subscription->id} as refunded",
            ['apiway_subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id],
        );

        return response()->json(['data' => $this->decorate($subscription->fresh())]);
    }

    /**
     * Surface the `meta` keys the Back Office acts on as first-class fields.
     * `needs_refund` has been written since API Way shipped and nothing has
     * ever read it — a captured payment with no instance was visible only in
     * the logs.
     */
    private function decorate(ApiwaySubscription $row): array
    {
        $meta = $row->meta ?? [];

        return array_merge($row->toArray(), [
            'needs_refund' => (bool) ($meta['needs_refund'] ?? false),
            'refund_settled_at' => $meta['refund_settled_at'] ?? null,
            'failure' => $meta['failure'] ?? null,
            'capacity_hold' => $meta['capacity_hold'] ?? null,
            'needs_attention' => $row->needsAttention(),
            'tenant_name' => $row->tenant?->user?->name,
            'tenant_email' => $row->tenant?->user?->email,
        ]);
    }
}
