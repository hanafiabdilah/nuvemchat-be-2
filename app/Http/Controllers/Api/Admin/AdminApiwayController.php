<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiwayPartnerException;
use App\Http\Controllers\Controller;
use App\Models\ApiwaySubscription;
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $subscriptions = ApiwaySubscription::query()
            ->with(['instances:id,apiway_subscription_id,provider_instance_id,name,status,connection_id', 'tenant.user:id,name,email'])
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json($subscriptions);
    }
}
