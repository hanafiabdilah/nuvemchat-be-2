<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\CouponDuration;
use App\Enums\Billing\CouponScope;
use App\Enums\Billing\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminCouponResource;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Market;
use App\Models\Plan;
use App\Services\Billing\Coupons;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Coupons in the Back Office: a percentage off plan charges, or a bonus on
 * balance top-ups.
 *
 * What a coupon has been used for is read off invoices (Coupons), so the list
 * shows it without a counter of its own. Once a charge has carried a coupon,
 * the fields a subscription was promised are locked; the rest (name, public,
 * active, dates, quota, limits for new redemptions) stays editable.
 */
class AdminCouponController extends Controller
{
    public function __construct(protected Coupons $coupons) {}

    public function index()
    {
        $coupons = Coupon::query()->orderByDesc('id')->get();
        $given = $this->givenByCoupon($coupons->pluck('id'));

        return AdminCouponResource::collection($coupons->map(function (Coupon $coupon) use ($given) {
            $coupon->setAttribute('usage', [
                'redemptions' => $this->coupons->redemptions($coupon),
                ...($given[$coupon->id] ?? ['paid_charges' => 0, 'given' => []]),
                'locked' => $this->hasBeenUsed($coupon, $given),
            ]);

            return $coupon;
        }));
    }

    /**
     * What the form offers to pick from — plans, markets, cycles — so nobody
     * types an id or a country code.
     */
    public function meta()
    {
        return response()->json([
            'data' => [
                'scopes' => array_map(fn (CouponScope $scope) => [
                    'key' => $scope->value,
                    'max_percent' => $scope->maxPercent(),
                ], CouponScope::cases()),
                'durations' => CouponDuration::values(),
                'billing_cycles' => array_map(fn (BillingCycle $c) => $c->value, BillingCycle::cases()),
                'plans' => Plan::query()
                    ->orderBy('sort_order')
                    ->get(['id', 'name', 'is_active', 'is_public'])
                    ->map(fn (Plan $plan) => [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'is_active' => (bool) $plan->is_active,
                        'is_public' => (bool) $plan->is_public,
                    ]),
                'markets' => Market::query()
                    ->orderBy('name')
                    ->get(['code', 'name', 'currency'])
                    ->map(fn (Market $market) => [
                        'code' => $market->code,
                        'name' => $market->name,
                        'currency' => $market->currency,
                    ]),
                'locked_fields' => Coupon::LOCKED_ONCE_USED,
            ],
        ]);
    }

    /** The coupon and the charges that carried it, most recent first. */
    public function show(Coupon $coupon)
    {
        $given = $this->givenByCoupon(collect([$coupon->id]));
        $coupon->setAttribute('usage', [
            'redemptions' => $this->coupons->redemptions($coupon),
            ...($given[$coupon->id] ?? ['paid_charges' => 0, 'given' => []]),
            'locked' => $this->hasBeenUsed($coupon, $given),
        ]);

        $charges = Invoice::query()
            ->with('tenant.user:id,name,email')
            ->where('coupon_id', $coupon->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'tenant' => $invoice->tenant ? [
                    'id' => $invoice->tenant->id,
                    // The workspace has no name of its own: the billing name, or its owner's.
                    'name' => $invoice->tenant->billing_name ?: $invoice->tenant->user?->name,
                    'email' => $invoice->tenant->user?->email,
                ] : null,
                'purpose' => $invoice->purpose?->value,
                'status' => $invoice->status?->value,
                'amount_cents' => $invoice->amount_cents,
                'coupon_discount_cents' => $invoice->coupon_discount_cents,
                'coupon_bonus_cents' => $invoice->coupon_bonus_cents,
                'currency' => $invoice->currency,
                'period_start' => $invoice->period_start?->toIso8601String(),
                'created_at' => $invoice->created_at?->toIso8601String(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => (new AdminCouponResource($coupon))->resolve(),
            'charges' => $charges,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $coupon = Coupon::create([
            ...$data,
            'created_by_admin_id' => $request->user()?->getKey(),
        ]);

        AuditLog::record('coupons.create', "Created coupon {$coupon->code}", [
            'coupon_id' => $coupon->id,
            ...$this->auditFields($coupon),
        ]);

        return (new AdminCouponResource($coupon))->response()->setStatusCode(201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $data = $this->validated($request, $coupon);

        if ($coupon->hasBeenUsed()) {
            $errors = [];

            foreach (Coupon::LOCKED_ONCE_USED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $current = $coupon->getAttribute($field);
                $current = $current instanceof \BackedEnum ? $current->value : $current;

                if ($current != $data[$field]) {
                    $errors[$field] = __('This coupon has already been used, so this can no longer change.');
                }
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        $before = $this->auditFields($coupon);
        $coupon->update($data);

        AuditLog::record('coupons.update', "Updated coupon {$coupon->code}", [
            'coupon_id' => $coupon->id,
            'before' => $before,
            'after' => $this->auditFields($coupon->fresh()),
        ]);

        return new AdminCouponResource($coupon->fresh());
    }

    public function destroy(Coupon $coupon)
    {
        // Charges point at it: deleting would leave invoices saying a coupon
        // was used without saying which. Turning it off stops new uses.
        if ($coupon->invoices()->exists()) {
            return response()->json([
                'message' => __('This coupon has already been used. Deactivate it instead of deleting it.'),
                'code' => 'coupon_in_use',
            ], 422);
        }

        AuditLog::record('coupons.delete', "Deleted coupon {$coupon->code}", [
            'coupon_id' => $coupon->id,
            ...$this->auditFields($coupon),
        ]);

        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted']);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Coupon $coupon = null): array
    {
        if ($request->has('code')) {
            $request->merge(['code' => Coupon::normalizeCode($request->input('code'))]);
        }

        $scope = CouponScope::tryFrom((string) $request->input('scope', $coupon?->scope?->value));

        $data = $request->validate([
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9_-]{3,32}$/',
                Rule::unique('coupons', 'code')->ignore($coupon?->id),
            ],
            'name' => ['required', 'string', 'max:80'],
            'scope' => ['required', Rule::enum(CouponScope::class)],
            'percent_off' => ['required', 'integer', 'min:1', 'max:'.($scope?->maxPercent() ?? 90)],
            'duration' => ['nullable', Rule::enum(CouponDuration::class)],
            'duration_cycles' => ['nullable', 'required_if:duration,repeating', 'integer', 'min:2', 'max:120'],
            'applies_to_addons' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['integer', 'exists:plans,id'],
            'billing_cycles' => ['nullable', 'array'],
            'billing_cycles.*' => [Rule::enum(BillingCycle::class)],
            'market_codes' => ['nullable', 'array'],
            'market_codes.*' => ['string', 'exists:markets,code'],
        ], [
            'code.regex' => __('Use 3 to 32 letters, numbers, - or _.'),
        ]);

        if (filled($data['starts_at'] ?? null) && filled($data['ends_at'] ?? null)
            && strtotime((string) $data['ends_at']) <= strtotime((string) $data['starts_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => __('The end date must be after the start date.'),
            ]);
        }

        $isPlan = $scope === CouponScope::Plan;
        $duration = $isPlan ? CouponDuration::tryFrom((string) ($data['duration'] ?? '')) ?? CouponDuration::Once : CouponDuration::Once;

        // A top-up coupon has no cycles, no extras and no plan limits: it is
        // the top-up it is typed on. Whatever the form sent for those is dropped.
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'scope' => $scope->value,
            'percent_off' => (int) $data['percent_off'],
            'duration' => $duration->value,
            'duration_cycles' => $duration === CouponDuration::Repeating ? (int) $data['duration_cycles'] : null,
            'applies_to_addons' => $isPlan && (bool) ($data['applies_to_addons'] ?? false),
            'is_public' => (bool) ($data['is_public'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'plan_ids' => $isPlan ? $this->listOrNull(array_map('intval', $data['plan_ids'] ?? [])) : null,
            'billing_cycles' => $isPlan ? $this->listOrNull($data['billing_cycles'] ?? []) : null,
            'market_codes' => $this->listOrNull($data['market_codes'] ?? []),
        ];
    }

    /** @return list<mixed>|null */
    protected function listOrNull(array $values): ?array
    {
        $values = array_values(array_unique($values));

        return $values ? $values : null;
    }

    /**
     * Paid charges and what was given, per coupon and per currency — amounts
     * in two currencies are not one number.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, array{paid_charges: int, given: list<array{currency: string, discount_cents: int, bonus_cents: int}>}>
     */
    protected function givenByCoupon(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $rows = Invoice::query()
            ->whereIn('coupon_id', $ids)
            ->where('status', InvoiceStatus::Paid->value)
            ->groupBy('coupon_id', 'currency')
            ->selectRaw('coupon_id, currency, COUNT(*) as charges, COALESCE(SUM(coupon_discount_cents), 0) as discount, COALESCE(SUM(coupon_bonus_cents), 0) as bonus')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row->coupon_id;
            $out[$id] ??= ['paid_charges' => 0, 'given' => []];
            $out[$id]['paid_charges'] += (int) $row->charges;
            $out[$id]['given'][] = [
                'currency' => $row->currency,
                'discount_cents' => (int) $row->discount,
                'bonus_cents' => (int) $row->bonus,
            ];
        }

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $given */
    protected function hasBeenUsed(Coupon $coupon, array $given): bool
    {
        return isset($given[$coupon->id]) || $coupon->hasBeenUsed();
    }

    /** @return array<string, mixed> */
    protected function auditFields(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code,
            'scope' => $coupon->scope?->value,
            'percent_off' => $coupon->percent_off,
            'duration' => $coupon->duration?->value,
            'duration_cycles' => $coupon->duration_cycles,
            'applies_to_addons' => $coupon->applies_to_addons,
            'is_public' => $coupon->is_public,
            'is_active' => $coupon->is_active,
            'starts_at' => $coupon->starts_at?->toIso8601String(),
            'ends_at' => $coupon->ends_at?->toIso8601String(),
            'max_redemptions' => $coupon->max_redemptions,
            'plan_ids' => $coupon->plan_ids,
            'billing_cycles' => $coupon->billing_cycles,
            'market_codes' => $coupon->market_codes,
        ];
    }
}
