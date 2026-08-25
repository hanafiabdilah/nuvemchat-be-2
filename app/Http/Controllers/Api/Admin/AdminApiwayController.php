<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiwayPartnerException;
use App\Http\Controllers\Controller;
use App\Models\ApiwaySubscription;
use App\Models\AuditLog;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
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
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], $e->getHttpStatus() >= 400 ? $e->getHttpStatus() : 502);
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $subscriptions = ApiwaySubscription::query()
            ->with(['instances:id,apiway_subscription_id,provider_instance_id,name,status,connection_id', 'tenant.user:id,name,email'])
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($validated['attention'] ?? false, fn ($q) => $q->needsAttention())
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        $subscriptions->setCollection(
            $subscriptions->getCollection()->map(fn (ApiwaySubscription $row) => $this->decorate($row)),
        );

        return response()->json($subscriptions);
    }

    /**
     * Record that a flagged refund was actually paid back.
     *
     * Without this the health check below never clears, and an alert that can
     * only go red is an alert operators learn to scroll past. Money moves at
     * MercadoPago, by hand — this only writes down that it happened.
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
        ]);
    }
}
