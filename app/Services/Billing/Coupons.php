<?php

namespace App\Services\Billing;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\CouponScope;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Exceptions\Billing\CouponException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Coupons: who may use one, and what it takes off (or adds to) a charge.
 *
 * Everything about usage is read off invoices, never counted beside them:
 *
 *  - a workspace has **used** a plan coupon once a charge carrying it was paid
 *    (one use per workspace);
 *  - a plan coupon's **redemptions** are the workspaces with such a charge,
 *    paid or still payable (an open one holds its place until it expires);
 *  - a top-up coupon is **not limited per workspace**: one workspace may use it
 *    on as many top-ups as it likes while it is active, in its window and has
 *    quota left — and its redemptions are those top-ups, counted one by one;
 *  - a subscription's **cycles used** are its paid plan invoices carrying the
 *    coupon, plus what a replaced plan had already used (`cycles_used_before`).
 *
 * A plan coupon is priced from `subscriptions.coupon_terms`, the snapshot taken
 * at checkout, so editing a coupon never changes a running subscription. A
 * top-up coupon applies to the one top-up it is typed on, its bonus frozen on
 * the invoice.
 *
 * ⚠️ Only at checkout. A coupon enters a subscription when it is bought (or is
 * carried over by an upgrade) — never added to a running one.
 */
class Coupons
{
    /**
     * How long an unpaid charge holds a coupon: the Pix window (24 hours, see
     * BillingService::PIX_WINDOW_HOURS) plus a margin.
     * Past it the charge is dead money, and the coupon is free again.
     */
    public const PENDING_HOLD_HOURS = 25;

    /**
     * Codes that do not exist, per workspace, before it has to wait. Counted
     * here rather than on a route because every checkout endpoint takes a
     * code — the quote among them, which the page calls on every change.
     */
    public const MAX_MISSES = 20;

    public const MISS_WINDOW_SECONDS = 600;

    public function findByCode(?string $code): ?Coupon
    {
        $code = Coupon::normalizeCode($code);

        return $code === '' ? null : Coupon::where('code', $code)->first();
    }

    /**
     * A coupon typed (or picked) for a plan charged now. Refused with a code
     * the dashboard can translate.
     */
    public function resolveForPlan(Tenant $tenant, Plan $plan, string $code): Coupon
    {
        $coupon = $this->lookup($tenant, $code);

        if ($problem = $this->problem($coupon, $tenant, CouponScope::Plan, $plan)) {
            throw new CouponException($problem);
        }

        return $coupon;
    }

    public function resolveForTopup(Tenant $tenant, string $code): Coupon
    {
        $coupon = $this->lookup($tenant, $code);

        if ($problem = $this->problem($coupon, $tenant, CouponScope::Topup)) {
            throw new CouponException($problem);
        }

        return $coupon;
    }

    /** A typed code, counting the ones that do not exist. */
    protected function lookup(Tenant $tenant, string $code): Coupon
    {
        $key = "coupon-miss:{$tenant->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_MISSES)) {
            throw new CouponException('too_many');
        }

        $coupon = $this->findByCode($code);

        if ($coupon === null) {
            RateLimiter::hit($key, self::MISS_WINDOW_SECONDS);

            throw new CouponException('not_found');
        }

        return $coupon;
    }

    /**
     * Why this workspace cannot redeem this coupon now, or null.
     *
     * The order is the order a customer can act on: the wrong kind of coupon
     * first, then whether it is live at all, then whether it is for them.
     */
    public function problem(Coupon $coupon, Tenant $tenant, CouponScope $scope, ?Plan $plan = null): ?string
    {
        if ($coupon->scope !== $scope) {
            return $coupon->scope === CouponScope::Plan ? 'plan_only' : 'topup_only';
        }

        if (! $coupon->is_active) {
            return 'inactive';
        }

        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            return 'not_started';
        }

        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            return 'expired';
        }

        if ($coupon->market_codes && ! in_array($tenant->market_code, $coupon->market_codes, true)) {
            return 'market';
        }

        if ($plan !== null && $scope === CouponScope::Plan) {
            if ($coupon->plan_ids && ! in_array((int) $plan->id, array_map('intval', $coupon->plan_ids), true)) {
                return 'plan';
            }

            if ($coupon->billing_cycles && ! in_array($plan->billing_cycle->value, $coupon->billing_cycles, true)) {
                return 'cycle';
            }
        }

        // A plan coupon is once per workspace; a top-up coupon is not limited
        // per workspace at all — only by being live and by its quota.
        if ($scope === CouponScope::Plan && $this->tenantHasUsed($coupon, $tenant)) {
            return 'already_used';
        }

        if ($coupon->max_redemptions !== null && $this->redemptions($coupon, exceptTenantId: $tenant->id) >= $coupon->max_redemptions) {
            return 'exhausted';
        }

        return null;
    }

    /**
     * The public coupons this workspace can use right now — the list the
     * checkout and the top-up modal show. A plan narrows it to the coupons
     * valid for that plan and cycle.
     *
     * @return Collection<int, Coupon>
     */
    public function available(Tenant $tenant, CouponScope $scope, ?Plan $plan = null): Collection
    {
        return Coupon::query()
            ->where('scope', $scope->value)
            ->where('is_public', true)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('percent_off')
            ->orderBy('id')
            ->get()
            ->filter(fn (Coupon $coupon) => $this->problem($coupon, $tenant, $scope, $plan) === null)
            ->values();
    }

    // --- Amounts -----------------------------------------------------------

    /**
     * `$percent` of `$baseCents`, rounded down to what the currency is written
     * in — a rupiah has no cents, so a discount of Rp 14.990,50 is Rp 14.990.
     * Down, never up: the customer is never shown a discount bigger than the
     * percentage they were promised.
     */
    public function percentOf(int $baseCents, int $percent, ?string $currency): int
    {
        return Money::percentOf($baseCents, $percent, $currency);
    }

    /**
     * What a plan coupon takes off one cycle: the plan, and the extras too
     * when the coupon includes them.
     *
     * @param  array<string, mixed>  $terms  A coupon_terms snapshot.
     */
    public function planDiscount(array $terms, int $planCents, int $addonsCents, ?string $currency): int
    {
        $base = $planCents + (($terms['applies_to_addons'] ?? false) ? $addonsCents : 0);

        return $this->percentOf($base, (int) ($terms['percent_off'] ?? 0), $currency);
    }

    /**
     * The coupon's part of the cycle starting at `$periodStart`, or null when
     * the subscription has none for that cycle (no coupon, cycles used up, or
     * a plan/cycle the coupon was never valid for — a downgrade onto a plan
     * the coupon excludes).
     *
     * @return array{coupon_id: int, discount_cents: int}|null
     */
    public function forPeriod(
        Subscription $subscription,
        CarbonInterface $periodStart,
        ?Plan $plan,
        ?BillingCycle $cycle,
        int $planCents,
        int $addonsCents,
    ): ?array {
        $terms = $subscription->coupon_terms;

        if (! is_array($terms) || empty($terms['coupon_id'])) {
            return null;
        }

        if (! $this->termsAllow($terms, $plan, $cycle)) {
            return null;
        }

        $cycles = $terms['cycles'] ?? null;

        if ($cycles !== null) {
            $used = (int) ($terms['cycles_used_before'] ?? 0) + $this->paidCycles($subscription, $periodStart);

            if ($used >= (int) $cycles) {
                return null;
            }
        }

        $discount = $this->planDiscount($terms, $planCents, $addonsCents, $subscription->currency);

        return $discount > 0 ? ['coupon_id' => (int) $terms['coupon_id'], 'discount_cents' => $discount] : null;
    }

    /**
     * Cycles left after the ones already paid, or null when the coupon has no
     * end. Zero once it has run out.
     */
    public function cyclesLeft(Subscription $subscription): ?int
    {
        $terms = $subscription->coupon_terms;

        if (! is_array($terms) || empty($terms['coupon_id'])) {
            return 0;
        }

        $cycles = $terms['cycles'] ?? null;

        if ($cycles === null) {
            return null;
        }

        return max(0, (int) $cycles - (int) ($terms['cycles_used_before'] ?? 0) - $this->paidCycles($subscription));
    }

    /**
     * What an upgrade carries over of the replaced plan's coupon: its terms,
     * with the cycles it already used, when it has cycles left and the new
     * plan is one it is valid for. Null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function carriedTerms(?Subscription $current, Plan $plan): ?array
    {
        $terms = $current?->coupon_terms;

        if (! is_array($terms) || empty($terms['coupon_id'])) {
            return null;
        }

        if (! $this->termsAllow($terms, $plan, $plan->billing_cycle)) {
            return null;
        }

        $used = (int) ($terms['cycles_used_before'] ?? 0) + $this->paidCycles($current);
        $cycles = $terms['cycles'] ?? null;

        if ($cycles !== null && $used >= (int) $cycles) {
            return null;
        }

        return array_merge($terms, ['cycles_used_before' => $used]);
    }

    /**
     * The coupon as the checkout and the Billing page show it.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    public function describeTerms(array $terms): array
    {
        return [
            'id' => (int) $terms['coupon_id'],
            'code' => $terms['code'],
            'name' => $terms['name'] ?? $terms['code'],
            'percent_off' => (int) $terms['percent_off'],
            'duration' => $terms['duration'],
            'cycles' => $terms['cycles'] ?? null,
            'applies_to_addons' => (bool) ($terms['applies_to_addons'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    public function describe(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'scope' => $coupon->scope->value,
            'percent_off' => $coupon->percent_off,
            'duration' => $coupon->duration->value,
            'cycles' => $coupon->cycles(),
            'applies_to_addons' => $coupon->scope === CouponScope::Plan && $coupon->applies_to_addons,
            'ends_at' => $coupon->ends_at?->toIso8601String(),
        ];
    }

    // --- Usage, read off invoices -------------------------------------------

    /**
     * Run a redemption under the coupon's lock when it has a cap, so two
     * workspaces paying at the same moment cannot both take the last one.
     * Uncapped coupons have nothing to race for and skip the lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function underCap(?Coupon $coupon, callable $callback): mixed
    {
        if ($coupon === null || $coupon->max_redemptions === null) {
            return $callback();
        }

        try {
            return Cache::lock("coupon:redeem:{$coupon->id}", 60)->block(15, $callback);
        } catch (LockTimeoutException) {
            throw new CouponException('busy');
        }
    }

    /**
     * How much of the coupon's quota is taken.
     *
     * A plan coupon counts **workspaces** holding it (a paid charge, or one
     * still payable) — `$exceptTenantId` leaves out the workspace redeeming
     * now, whose own earlier open checkout must not count against it. A top-up
     * coupon counts **top-ups**: one workspace can use it many times, so each
     * use takes one from the quota.
     */
    public function redemptions(Coupon $coupon, ?int $exceptTenantId = null): int
    {
        $charges = $this->holdingCharges($coupon);

        if ($coupon->scope === CouponScope::Topup) {
            return $charges->count();
        }

        return $charges
            ->when($exceptTenantId, fn (Builder $q) => $q->where('tenant_id', '!=', $exceptTenantId))
            ->distinct()
            ->count('tenant_id');
    }

    public function tenantHasUsed(Coupon $coupon, Tenant $tenant): bool
    {
        return Invoice::query()
            ->where('coupon_id', $coupon->id)
            ->where('tenant_id', $tenant->id)
            ->where('purpose', $coupon->invoicePurpose()->value)
            ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Refunded->value])
            ->exists();
    }

    /**
     * Paid plan invoices of this subscription that carried its coupon,
     * optionally only for cycles that started before `$before`.
     */
    public function paidCycles(Subscription $subscription, ?CarbonInterface $before = null): int
    {
        $couponId = $subscription->coupon_terms['coupon_id'] ?? null;

        if (! $couponId) {
            return 0;
        }

        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('purpose', InvoicePurpose::Subscription->value)
            ->where('coupon_id', $couponId)
            ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Refunded->value])
            ->when($before, fn (Builder $q) => $q->where('period_start', '<', $before))
            ->count();
    }

    /** @return Builder<Invoice> */
    protected function holdingCharges(Coupon $coupon): Builder
    {
        return Invoice::query()
            ->where('coupon_id', $coupon->id)
            ->where(fn (Builder $q) => $q
                ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Refunded->value])
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', InvoiceStatus::Pending->value)
                    ->where('created_at', '>=', now()->subHours(self::PENDING_HOLD_HOURS))));
    }

    /** @param  array<string, mixed>  $terms */
    protected function termsAllow(array $terms, ?Plan $plan, ?BillingCycle $cycle): bool
    {
        $plans = $terms['plan_ids'] ?? null;

        if ($plans && (! $plan || ! in_array((int) $plan->id, array_map('intval', $plans), true))) {
            return false;
        }

        $cycles = $terms['billing_cycles'] ?? null;

        return ! ($cycles && (! $cycle || ! in_array($cycle->value, $cycles, true)));
    }
}
