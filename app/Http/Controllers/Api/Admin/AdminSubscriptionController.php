<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\Billing\PaymentAlreadySettledException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminSubscriptionController extends Controller
{
    public function __construct(
        protected BillingService $billing,
    ) {}

    /**
     * Platform-wide subscription list with optional status filter.
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $subscriptions = Subscription::query()
            ->with(['plan', 'tenant.user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return SubscriptionResource::collection($subscriptions);
    }

    public function show(Tenant $tenant)
    {
        $subscription = $tenant->currentSubscription?->loadMissing('plan');

        return response()->json([
            'data' => $subscription ? new SubscriptionResource($subscription) : null,
        ]);
    }

    /**
     * Manually grant / override a subscription for a tenant (comp, no payment).
     */
    public function assign(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'plan_id' => ['nullable', 'exists:plans,id'],
            'ends_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = isset($validated['plan_id']) ? Plan::find($validated['plan_id']) : null;
        $endsAt = isset($validated['ends_at']) ? Carbon::parse($validated['ends_at']) : null;

        $subscription = $this->billing->grantManual(
            $tenant,
            $plan,
            $endsAt,
            $request->user(),
            $validated['note'] ?? null,
        );

        return (new SubscriptionResource($subscription->loadMissing('plan')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Cancel the tenant's current subscription.
     */
    public function cancel(Tenant $tenant)
    {
        $subscription = $tenant->currentSubscription;
        abort_if($subscription === null, 404, 'No subscription');

        try {
            $this->billing->cancel($subscription);
        } catch (PaymentAlreadySettledException) {
            // The tenant's pix cleared mid-cancel — the subscription is live now.
            return response()->json([
                'message' => 'O pagamento já foi confirmado. A assinatura permanece ativa.',
                'code' => 'payment_already_settled',
                'data' => new SubscriptionResource($subscription->fresh()->loadMissing('plan')),
            ], 409);
        }

        return response()->json(['data' => new SubscriptionResource($subscription->fresh()->loadMissing('plan'))]);
    }
}
