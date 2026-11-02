<?php

namespace App\Services\Billing;

use App\Enums\Billing\Addon;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\HoldsRecurringAuthorisations;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What moving from the current plan to another one costs, and when it happens.
 *
 * One rule, read the same way by the checkout (which shows it) and by
 * BillingService (which charges it), so the number on the screen is the number
 * on the invoice:
 *
 *  - **Upgrade** — the new plan commits to more: a cycle at least as long and a
 *    higher price per cycle. Applied *now*. The part of the current plan that is
 *    paid for and not yet used is credited: taken off the first charge, or —
 *    where that charge cannot carry a discount — paid into the balance. The old
 *    plan keeps working until the new one is paid; nobody is left without a
 *    plan because a Pix is still in their bank app.
 *  - **Downgrade** — anything else. Applied at the end of what is already paid
 *    for, on the same subscription row. Nothing is charged now and nothing is
 *    taken away early; the money already paid buys exactly what it was paid for.
 *  - **New** — there is no paid, running plan to credit or to wait for (no plan,
 *    an unpaid checkout, a lapsed or comped one): an ordinary subscription.
 *
 * Extras (agents, WhatsApp connections) count as part of what a plan costs: the
 * comparison, the credit and every renewal read the plan *with* the capacity
 * bought on top of it, at **the new plan's** price per extra — a bigger plan
 * sells them cheaper.
 *
 * ⚠️ The credit is read off **paid invoices**, never off the plan's list price:
 * an invoice is what the customer actually bought, for the period it names.
 * That is what makes a renewal charged three days early (billing:charge-renewals
 * runs ahead of the period end) count as the prepaid cycle it is, and what makes
 * a previous upgrade's discounted invoice still worth the full plan.
 */
class PlanChange
{
    public const NEW = 'new';

    public const UPGRADE = 'upgrade';

    public const DOWNGRADE = 'downgrade';

    public const CURRENT = 'current';

    public function __construct(protected BillingGateways $gateways) {}

    /**
     * `$addons` is the capacity bought on top of the new plan, in units by
     * add-on key. Null keeps the capacity the workspace already has (see
     * carriedUnits()), so a client that does not know about extras never
     * drops them by changing plan.
     *
     * @param  array<string, mixed>|null  $addons
     * @return array<string, mixed>
     */
    public function quote(Tenant $tenant, Plan $plan, ?PaymentMethod $method = null, ?CarbonInterface $at = null, bool $autoRenew = true, ?array $addons = null): array
    {
        $at = $at ? Carbon::instance($at) : now();
        $price = $plan->priceForMarket($tenant->market_code);

        if ($price === null) {
            throw ValidationException::withMessages([
                'plan_id' => __('This plan is not available in your country.'),
            ]);
        }

        $priceCents = (int) $price->amount_cents;
        $currency = $price->currency ?: ($plan->currency ?: $tenant->currency());
        $current = $tenant->currentSubscription?->loadMissing(['plan', 'scheduledPlan']);

        $unitPrices = $plan->addonUnitPrices($tenant->market_code);
        $units = self::addonUnitsFor($unitPrices, $addons ?? $this->carriedUnits($current, $plan, $unitPrices));
        $lines = self::addonLines($units, $unitPrices);
        $addonsCents = array_sum(array_column($lines, 'total_cents'));
        $totalCents = $priceCents + $addonsCents;

        $type = $this->classify($current, $plan, $totalCents, $currency, $at);

        $base = [
            'type' => $type,
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price_cents' => $priceCents,
                'currency' => $currency,
                'billing_cycle' => $plan->billing_cycle->value,
            ],
            'addons' => [
                'units' => $units,
                'lines' => $lines,
                'total_cents' => $addonsCents,
                // One unit of every extra this plan sells, per cycle — what a
                // checkout needs to price units not chosen yet — and what the
                // plan already includes of each.
                'unit_cents' => $unitPrices,
                'included' => self::includedFor($plan, array_keys($unitPrices)),
                'max_units' => Addon::MAX_UNITS,
            ],
            // The plan and its extras, per cycle: what every renewal charges.
            'total_cents' => $totalCents,
            'current' => $type === self::NEW ? null : $this->describe($current, $tenant),
            'credit_cents' => 0,
            'remaining_days' => 0,
            'discount_cents' => 0,
            'balance_credit_cents' => 0,
            'charge_now_cents' => 0,
            'effective_at' => $at->toIso8601String(),
            'next_renewal_at' => null,
            'next_renewal_cents' => $totalCents,
            'blocked_reason' => null,
            'scheduled' => false,
        ];

        return match ($type) {
            self::UPGRADE => $this->upgradeTerms($base, $current, $plan, $totalCents, $method, $at, $autoRenew),
            self::DOWNGRADE => $this->downgradeTerms($base, $current, $plan),
            self::CURRENT => array_merge($base, [
                'effective_at' => $current->current_period_end?->toIso8601String(),
                'next_renewal_at' => $current->current_period_end?->toIso8601String(),
                'next_renewal_cents' => $current->cycleTotalCents(),
            ]),
            default => array_merge($base, [
                'charge_now_cents' => $totalCents,
                'next_renewal_at' => $plan->billing_cycle->advance($at)->toIso8601String(),
            ]),
        };
    }

    /**
     * The units a plan can take from a request: whole, non-negative, capped,
     * and only for extras this plan sells — units for anything else are
     * dropped rather than charged.
     *
     * @param  array<string, int>  $unitPrices  addonUnitPrices() of the plan.
     * @param  array<string, mixed>  $requested
     * @return array<string, int>
     */
    public static function addonUnitsFor(array $unitPrices, array $requested): array
    {
        return array_intersect_key(Addon::clean($requested), $unitPrices);
    }

    /**
     * @param  array<string, int>  $units
     * @param  array<string, int>  $unitPrices
     * @return list<array{key: string, quantity: int, unit_cents: int, total_cents: int}>
     */
    public static function addonLines(array $units, array $unitPrices): array
    {
        $lines = [];

        foreach ($units as $key => $quantity) {
            $unit = (int) ($unitPrices[$key] ?? 0);
            $lines[] = ['key' => $key, 'quantity' => $quantity, 'unit_cents' => $unit, 'total_cents' => $unit * $quantity];
        }

        return $lines;
    }

    /**
     * What a plan includes of each quota an extra raises.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public static function includedFor(Plan $plan, array $keys): array
    {
        $quotas = $plan->quotas ?? [];
        $included = [];

        foreach ($keys as $key) {
            $included[$key] = (int) ($quotas[Addon::from($key)->quota()->value] ?? 0);
        }

        return $included;
    }

    /**
     * The extras a plan change keeps when nobody chose otherwise: enough units
     * on the new plan to keep the **capacity** the workspace has now.
     *
     * Not the same number of units: three extra agents on a plan with two
     * included is five seats, and a plan that includes five needs none — moving
     * the three across would charge for eight.
     *
     * @param  array<string, int>  $unitPrices
     * @return array<string, int>
     */
    public function carriedUnits(?Subscription $current, Plan $plan, array $unitPrices): array
    {
        if ($current === null) {
            return [];
        }

        $held = $current->entitlements()['quotas'];
        $included = $plan->quotas ?? [];
        $units = [];

        foreach (array_keys($unitPrices) as $key) {
            $quota = Addon::from($key)->quota()->value;

            // Only from a limit: an unlimited allowance has no number to keep.
            if (! isset($held[$quota], $included[$quota])) {
                continue;
            }

            $units[$key] = max(0, (int) $held[$quota] - (int) $included[$quota]);
        }

        return array_filter($units);
    }

    /**
     * What is paid for and not yet used on a subscription, in its own money.
     *
     * @return array{cents: int, seconds: int}
     */
    public function unusedValue(Subscription $subscription, ?CarbonInterface $at = null): array
    {
        $at = $at ? Carbon::instance($at) : now();
        $cents = 0.0;
        $seconds = 0;

        // The extras bought mid-period are paid for too: what is left of them
        // is credited like what is left of the plan.
        $invoices = Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('purpose', [InvoicePurpose::Subscription->value, InvoicePurpose::SubscriptionAddon->value])
            ->where('status', InvoiceStatus::Paid->value)
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->where('period_end', '>', $at)
            ->get();

        foreach ($invoices as $invoice) {
            $length = $invoice->period_start->diffInSeconds($invoice->period_end, false);

            if ($length <= 0) {
                continue;
            }

            $from = $invoice->period_start->greaterThan($at) ? $invoice->period_start : $at;
            $left = max(0, $from->diffInSeconds($invoice->period_end, false));
            // The period's whole value: an upgrade's first invoice carries a
            // discount, but what it bought is the full plan for that period.
            $value = (int) $invoice->amount_cents + (int) ($invoice->proration_credit_cents ?? 0);

            $cents += $value * min(1, $left / $length);
            $seconds += (int) $left;
        }

        // Down, never up: a credit is our money, and rounding it in the
        // customer's favour by a cent per switch is a cent nobody can explain.
        return ['cents' => (int) floor($cents), 'seconds' => $seconds];
    }

    /**
     * The price, cycle and plan a subscription is billed at for the period
     * starting at `$periodStart` — the scheduled downgrade once it applies,
     * the current terms before.
     *
     * Used by every renewal path, which run ahead of the period they bill
     * (a card three days early, a Pix QR issued days before): the invoice for
     * the cycle after a downgrade must already be at the lower price, while
     * the plan itself only changes when that cycle actually starts.
     *
     * `price_cents` is the whole cycle — the plan and its extras — so every
     * renewal path charges both in one payment without knowing extras exist.
     *
     * @return array{price_cents: int, plan_cents: int, addons_cents: int, addons: array<string, int>, cycle: BillingCycle, plan: ?Plan, scheduled: bool}
     */
    public static function termsFor(Subscription $subscription, CarbonInterface $periodStart): array
    {
        $start = Carbon::instance($periodStart);
        $scheduled = $subscription->hasScheduledChange()
            && $start->greaterThanOrEqualTo($subscription->scheduled_change_at);

        $planCents = (int) ($scheduled ? $subscription->scheduled_price_cents : $subscription->price_cents);

        $units = $subscription->hasScheduledAddons() && $start->greaterThanOrEqualTo($subscription->scheduled_addons_at)
            ? $subscription->scheduledAddonQuantities()
            : $subscription->addonQuantities();
        // A downgrade prices its extras at the plan it moves to.
        $prices = $scheduled && $subscription->scheduled_addon_unit_cents !== null
            ? $subscription->scheduled_addon_unit_cents
            : $subscription->addon_unit_cents;
        $addonsCents = $subscription->addonsCents($units, $prices ?? []);

        return [
            'price_cents' => $planCents + $addonsCents,
            'plan_cents' => $planCents,
            'addons_cents' => $addonsCents,
            'addons' => $units,
            'cycle' => $scheduled
                ? ($subscription->scheduled_billing_cycle ?? $subscription->billing_cycle)
                : $subscription->billing_cycle,
            'plan' => $scheduled ? $subscription->scheduledPlan : $subscription->plan,
            'scheduled' => $scheduled,
        ];
    }

    /**
     * Why a downgrade cannot be scheduled on this subscription, or null.
     *
     * A Mercado Pago preapproval can change its amount but not its frequency,
     * so a downgrade that also changes the cycle would keep charging on the
     * old one. Said before the button, not discovered at the renewal.
     */
    public function downgradeBlocker(Subscription $current, Plan $plan): ?string
    {
        $instrument = $current->payment_instrument_id;

        if ($instrument === null || $plan->billing_cycle === $current->billing_cycle) {
            return null;
        }

        $gateway = $this->gateways->forSubscription($current);

        if ($gateway instanceof HoldsRecurringAuthorisations && $gateway->renewsItself($instrument)) {
            return __('A card subscription cannot change its billing cycle. Cancel the current plan and choose the new one when this period ends.');
        }

        return null;
    }

    /** `$totalCents`: the new plan with the extras chosen for it, per cycle. */
    protected function classify(?Subscription $current, Plan $plan, int $totalCents, string $currency, Carbon $at): string
    {
        if (! $this->isCreditable($current, $currency, $at)) {
            return self::NEW;
        }

        $currentCycle = $current->billing_cycle ?? $current->plan?->billing_cycle ?? BillingCycle::Monthly;

        // The same plan at another cycle is a change like any other (monthly →
        // yearly is an upgrade, paid now with the month's unused part credited);
        // only the same plan at the same cycle is "already yours".
        if ((int) $current->plan_id === (int) $plan->id && $plan->billing_cycle === $currentCycle) {
            return self::CURRENT;
        }

        return $plan->billing_cycle->rank() >= $currentCycle->rank() && $totalCents > $current->cycleTotalCents()
            ? self::UPGRADE
            : self::DOWNGRADE;
    }

    /**
     * A running plan somebody paid for: the only kind there is something to
     * credit from, or a period end to wait for.
     */
    protected function isCreditable(?Subscription $current, string $currency, Carbon $at): bool
    {
        return $current !== null
            && $current->isUsable()
            && $current->payment_method !== PaymentMethod::Manual
            && $current->cycleTotalCents() > 0
            && $current->current_period_end !== null
            && $current->current_period_end->greaterThan($at)
            && strtoupper((string) ($current->currency ?: 'BRL')) === strtoupper($currency);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    protected function upgradeTerms(array $base, Subscription $current, Plan $plan, int $totalCents, ?PaymentMethod $method, Carbon $at, bool $autoRenew = true): array
    {
        $unused = $this->unusedValue($current, $at);
        $credit = $unused['cents'];

        // A card here is a standing authorisation for a fixed amount: its first
        // debit is that amount, so the credit cannot ride on it.
        // A card paid by hand each cycle is an ordinary one-off charge, so it
        // carries the credit like a Pix does.
        $fixedFirstCharge = $method === PaymentMethod::Card
            && $autoRenew
            && $this->gateways->forCardSubscription($current->tenant) instanceof HoldsRecurringAuthorisations;

        if ($fixedFirstCharge) {
            $discount = 0;
        } else {
            // Always at least one day of the new plan: a credit bigger than the
            // whole price (a prepaid cycle, a yearly plan) would otherwise make
            // a zero charge, which no gateway takes and which stores no card.
            // What does not fit as a discount goes to the balance instead.
            $cycleSeconds = max(1, $at->diffInSeconds($plan->billing_cycle->advance($at), false));
            $floor = min($totalCents, (int) ceil($totalCents * 86400 / $cycleSeconds));
            $discount = min($credit, max(0, $totalCents - $floor));
        }

        return array_merge($base, [
            'credit_cents' => $credit,
            'remaining_days' => (int) ceil($unused['seconds'] / 86400),
            'discount_cents' => $discount,
            'balance_credit_cents' => max(0, $credit - $discount),
            'charge_now_cents' => $totalCents - $discount,
            'next_renewal_at' => $plan->billing_cycle->advance($at)->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    protected function downgradeTerms(array $base, Subscription $current, Plan $plan): array
    {
        $effective = $current->current_period_end;

        return array_merge($base, [
            'effective_at' => $effective->toIso8601String(),
            'next_renewal_at' => $effective->toIso8601String(),
            'blocked_reason' => $this->downgradeBlocker($current, $plan),
            'scheduled' => (int) $current->scheduled_plan_id === (int) $plan->id
                && ($current->scheduled_billing_cycle ?? $current->billing_cycle) === $plan->billing_cycle,
        ]);
    }

    /** @return array<string, mixed> */
    protected function describe(Subscription $current, Tenant $tenant): array
    {
        $plan = $current->plan?->applyMarketPrice(
            $tenant->market_code,
            $current->plan->isSoldIn($tenant->market_code, $current->billing_cycle) ? $current->billing_cycle : null,
        );

        return [
            'subscription_id' => $current->id,
            'plan_id' => $current->plan_id,
            'name' => $plan?->name,
            'price_cents' => (int) $current->price_cents,
            'addons' => $current->addonQuantities(),
            'addons_cents' => $current->addonsCents(),
            'total_cents' => $current->cycleTotalCents(),
            'currency' => $current->currency,
            'billing_cycle' => $current->billing_cycle?->value,
            'payment_method' => $current->payment_method?->value,
            'period_end' => $current->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $current->cancel_at_period_end,
        ];
    }
}
