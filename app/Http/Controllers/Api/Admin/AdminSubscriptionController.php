<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\PaymentAlreadySettledException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminSubscriptionController extends Controller
{
    public function __construct(
        protected BillingService $billing,
    ) {}

    /** The filters that take several values, sent as `a,b` or as `a[]=…`. */
    private const LIST_FILTERS = ['status', 'plan_id', 'payment_method', 'billing_cycle', 'market'];

    /**
     * Platform-wide subscription list.
     *
     * Every filter narrows the same query, and each list filter is any-of:
     * `status=past_due,grace` is "either of those". `current=1` keeps only the
     * row each workspace is on today — a plan change or an abandoned checkout
     * leaves its old row behind, and those outnumber the live ones over time.
     */
    public function index(Request $request)
    {
        $request->merge($this->normalizeLists($request));

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(SubscriptionStatus::class)],
            'plan_id' => ['sometimes', 'array'],
            'plan_id.*' => ['integer'],
            'payment_method' => ['sometimes', 'array'],
            'payment_method.*' => [Rule::enum(PaymentMethod::class)],
            'billing_cycle' => ['sometimes', 'array'],
            'billing_cycle.*' => [Rule::enum(BillingCycle::class)],
            'market' => ['sometimes', 'array'],
            'market.*' => ['string', 'size:2'],
            'current' => ['sometimes', 'boolean'],
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $subscriptions = Subscription::query()
            ->with(['plan', 'tenant.user'])
            ->when($validated['status'] ?? [], fn ($q, $values) => $q->whereIn('status', $values))
            ->when($validated['plan_id'] ?? [], fn ($q, $values) => $q->whereIn('plan_id', $values))
            ->when($validated['payment_method'] ?? [], fn ($q, $values) => $q->whereIn('payment_method', $values))
            ->when($validated['billing_cycle'] ?? [], fn ($q, $values) => $q->whereIn('billing_cycle', $values))
            ->when($validated['market'] ?? [], fn ($q, $values) => $q->whereHas(
                'tenant',
                fn ($t) => $t->whereIn('market_code', array_map('strtoupper', $values)),
            ))
            ->when($validated['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($validated['current'] ?? false, fn ($q) => $q->whereHas(
                'tenant',
                fn ($t) => $t->whereColumn('tenants.current_subscription_id', 'subscriptions.id'),
            ))
            ->when($search !== '', fn ($q) => $this->applySearch($q, $search))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return SubscriptionResource::collection($subscriptions);
    }

    /**
     * What the filters on the Subscriptions page pick from. Served here, under
     * the same permission as the list, rather than read from /admin/plans and
     * /admin/markets — those sit behind the permissions that edit a plan or
     * open a country, which the person reading subscriptions may not hold.
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'data' => [
                'statuses' => array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::cases()),
                'payment_methods' => array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases()),
                'billing_cycles' => array_map(fn (BillingCycle $c) => $c->value, BillingCycle::cases()),
                // Retired plans included: their subscribers are still here.
                'plans' => Plan::query()
                    ->orderByDesc('is_active')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_active'])
                    ->map(fn (Plan $plan) => [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'is_active' => (bool) $plan->is_active,
                    ]),
                'markets' => Market::query()
                    ->orderBy('name')
                    ->get(['code', 'name'])
                    ->map(fn (Market $market) => ['code' => $market->code, 'name' => $market->name]),
            ],
        ]);
    }

    /**
     * One box for everything an operator arrives holding: the owner's name,
     * e-mail or WhatsApp, the billing name or CPF/CNPJ, a subscription or
     * workspace number, or the gateway id a webhook log printed.
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.$term.'%';
        // Phones and documents are stored as bare digits, so "+55 (11) 9…" or
        // "123.456.789-00" only match once the punctuation is gone. A short run
        // of digits is an id, not a phone: "12" would match half the numbers.
        $digits = preg_replace('/\D+/', '', $term);
        $digitsLike = strlen($digits) >= 5 ? '%'.$digits.'%' : null;

        $query->where(fn ($q) => $q
            ->when(ctype_digit($term), fn ($q) => $q
                ->orWhere('subscriptions.id', (int) $term)
                ->orWhere('subscriptions.tenant_id', (int) $term))
            ->orWhere('payment_instrument_id', 'like', $like)
            ->orWhere('payment_customer_id', 'like', $like)
            // Grouped inside each whereHas: an ungrouped orWhere there would be
            // one bad refactor away from escaping the relation's key.
            ->orWhereHas('tenant', fn ($t) => $t->where(fn ($t) => $t
                ->where('billing_name', 'like', $like)
                ->when($digitsLike, fn ($t, $d) => $t->orWhere('billing_document_number', 'like', $d))))
            ->orWhereHas('tenant.user', fn ($u) => $u->where(fn ($u) => $u
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->when($digitsLike, fn ($u, $d) => $u->orWhere('whatsapp_number', 'like', $d)))));
    }

    /**
     * `status=active,grace` and `status[]=active&status[]=grace` mean the same
     * thing; an empty value means no filter.
     *
     * @return array<string, list<string>>
     */
    private function normalizeLists(Request $request): array
    {
        $lists = [];

        foreach (self::LIST_FILTERS as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $raw = $request->input($key);
            $values = is_array($raw) ? $raw : explode(',', (string) $raw);

            $lists[$key] = array_values(array_filter(
                array_map(fn ($v) => trim((string) $v), $values),
                fn (string $v) => $v !== '',
            ));
        }

        return $lists;
    }

    public function show(Tenant $tenant)
    {
        $subscription = $tenant->currentSubscription?->loadMissing(['plan', 'tenant', 'scheduledPlan']);

        // With usage: the operator's question on this tab is "how close are
        // they to each limit", not only "what does the plan say".
        return response()->json([
            'data' => $subscription ? (new SubscriptionResource($subscription))->withUsage() : null,
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
