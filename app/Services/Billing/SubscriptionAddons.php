<?php

namespace App\Services\Billing;

use App\Enums\Billing\Addon;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\Quota;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\SavedCard;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Extra agents and WhatsApp connections bought on a running plan, one unit at
 * a time, at **the plan's** price for them.
 *
 * The rule, in one sentence: **a unit added today is paid now for what is left
 * of the paid period, and from the next renewal on it is simply part of the
 * plan's own charge.** A unit removed keeps working until the end of what is
 * paid for and stops being charged from the next period — paid periods are
 * never shortened, and there is no refund.
 *
 * Three things follow:
 *
 * - **Nothing is added before it is paid.** An increase is an invoice
 *   (`subscription_addon`) whose paid hook adds the units; a Pix left unpaid
 *   or a refused card changes nothing. A decrease needs no payment and is
 *   scheduled at once.
 * - **One bill afterwards.** The units live on the subscription (`addons`),
 *   and every renewal path reads PlanChange::termsFor(), which adds them to
 *   the plan's price — the next charge is the plan and its extras together,
 *   on whatever gateway already renews it (Stripe, Mercado Pago, Pix).
 * - **A renewal already paid is paid for too.** A card renews three days
 *   early and moves the period end with it; the prorated charge runs to that
 *   end, so it covers the prepaid cycle as well. A renewal issued but unpaid
 *   is closed instead, and issued again at the new total.
 *
 * Quantities are totals ("3 extra agents"), never deltas: a request repeated by
 * a double click asks for the same thing twice and gets it once.
 */
class SubscriptionAddons
{
    public function __construct(
        protected BillingService $billing,
        protected SubscriptionGate $gate,
    ) {}

    /**
     * What the workspace holds and can buy, for the Billing page.
     *
     * @return array<string, mixed>
     */
    public function state(Tenant $tenant): array
    {
        $subscription = $tenant->currentSubscription?->loadMissing('plan');
        $reason = $this->unavailableReason($tenant, $subscription);
        $prices = $subscription ? $this->unitPrices($subscription) : [];
        $included = $this->includedQuotas($subscription);
        $held = $subscription?->addonQuantities() ?? [];
        $scheduled = $this->scheduledReduction($subscription);

        $items = [];

        foreach (Addon::cases() as $addon) {
            $quota = $addon->quota()->value;

            $items[] = [
                'key' => $addon->value,
                'quota' => $quota,
                // Null: the plan does not limit it, so there is nothing to buy.
                'included' => isset($included[$quota]) ? (int) $included[$quota] : null,
                'available' => isset($prices[$addon->value]),
                'quantity' => $held[$addon->value] ?? 0,
                'scheduled_quantity' => $scheduled === null ? null : ($scheduled[$addon->value] ?? 0),
                'unit_cents' => $prices[$addon->value] ?? null,
                'max_units' => Addon::MAX_UNITS,
                'used' => $this->used($tenant, $addon),
            ];
        }

        $next = $subscription?->current_period_end
            ? PlanChange::termsFor($subscription, $subscription->current_period_end)
            : null;

        return [
            'available' => $reason === null,
            'reason' => $reason,
            'billing_cycle' => $subscription?->billing_cycle?->value,
            'currency' => $subscription?->currency,
            'period_end' => $subscription?->current_period_end?->toIso8601String(),
            'items' => $items,
            'addons_cents' => $subscription?->addonsCents() ?? 0,
            'next_renewal_cents' => $next['price_cents'] ?? null,
            'scheduled_at' => $scheduled !== null ? $subscription->scheduled_addons_at?->toIso8601String() : null,
            'payment_options' => $reason === null ? $this->billing->addonPaymentOptions($subscription) : [],
        ];
    }

    /**
     * What a change costs: the prorated charge for the units added, and what
     * the next renewal will be with the new totals.
     *
     * @param  array<string, mixed>  $requested  Units on top of the plan, by add-on key.
     * @return array<string, mixed>
     */
    public function quote(Tenant $tenant, array $requested): array
    {
        $subscription = $this->eligibleSubscription($tenant);
        $prices = $this->unitPrices($subscription);
        $targets = $this->targets($subscription, $requested, $prices);
        $held = $subscription->addonQuantities();
        $included = $this->includedQuotas($subscription);

        $cycle = $subscription->billing_cycle ?? BillingCycle::Monthly;
        $periodEnd = Carbon::instance($subscription->current_period_end);
        $length = max(1, $cycle->retreat($periodEnd)->diffInSeconds($periodEnd, false));
        $left = max(0, now()->diffInSeconds($periodEnd, false));
        // Not capped at one: a card that already renewed early moved the
        // period end a whole cycle ahead, and that prepaid cycle needs the
        // new units too.
        $fraction = $left / $length;

        $increases = [];
        $decreases = [];
        $charge = 0.0;
        $errors = [];

        foreach ($targets as $key => $target) {
            $addon = Addon::from($key);
            $from = $held[$key] ?? 0;
            $delta = $target - $from;
            $unit = (int) $prices[$key];

            if ($delta > 0) {
                // What is left of the period, and never less than one day of
                // it: a unit bought in the last minutes still has to be an
                // amount a gateway will take.
                $part = max($delta * $unit * $fraction, $delta * $unit * 86400 / $length);
                $charge += $part;

                $increases[] = [
                    'key' => $key, 'from' => $from, 'to' => $target,
                    'unit_cents' => $unit, 'charge_cents' => (int) ceil($part),
                ];
            } elseif ($delta < 0) {
                $plan = (int) ($included[$addon->quota()->value] ?? 0);
                $used = $this->used($tenant, $addon);

                // Refused below what is in use: agents and numbers past the
                // limit would have to go. The customer removes them first, on
                // purpose, rather than finding out at the renewal.
                if ($used > $plan + $target) {
                    $errors["addons.{$key}"] = __('You are using :used. Remove some before lowering the extras below :min.', [
                        'used' => $used,
                        'min' => max(0, $used - $plan),
                    ]);
                }

                $decreases[] = ['key' => $key, 'from' => $from, 'to' => $target];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $renewal = PlanChange::termsFor($subscription, $periodEnd);

        return [
            'charge_now_cents' => (int) ceil($charge),
            'covered_until' => $periodEnd->toIso8601String(),
            'increases' => $increases,
            'decreases' => $decreases,
            // Fewer units take effect when the paid period ends.
            'reductions_from' => $periodEnd->toIso8601String(),
            'targets' => $targets,
            'next_renewal_at' => $periodEnd->toIso8601String(),
            'next_renewal_cents' => (int) $renewal['plan_cents'] + $subscription->addonsCents($targets, $prices),
            'currency' => $subscription->currency,
            'payment_options' => $this->billing->addonPaymentOptions($subscription),
        ];
    }

    /**
     * Apply a change: reductions are scheduled now, additions become an
     * invoice that adds them once paid.
     *
     * @param  array<string, mixed>  $requested
     * @param  array{method?: ?string, saved_card?: ?SavedCard, card_token?: ?string, payer_email?: ?string}  $payment
     * @return array{state: array<string, mixed>, invoice: ?Invoice, payment: ?array<string, mixed>}
     */
    public function purchase(Tenant $tenant, array $requested, array $payment = []): array
    {
        $quote = $this->quote($tenant, $requested);
        $subscription = $tenant->currentSubscription;
        $held = $subscription->addonQuantities();
        $next = $this->nextPeriodUnits($quote['targets'], $held);
        $schedule = $next == $held ? null : $next;
        $current = $this->scheduledReduction($subscription);

        if (! $quote['increases'] && $schedule == $current) {
            throw ValidationException::withMessages(['addons' => __('Nothing to change.')]);
        }

        if ($quote['increases'] && blank($payment['method'] ?? null)) {
            throw ValidationException::withMessages(['method' => __('Choose how to pay for the extras.')]);
        }

        if ($schedule != $current) {
            // Gateway first, like a downgrade: a preapproval left at the old
            // amount would charge for units the customer took away.
            $this->billing->syncRecurringAmount(
                $subscription,
                (int) PlanChange::termsFor($subscription, $subscription->current_period_end)['plan_cents']
                    + $subscription->addonsCents($schedule ?? $held),
            );

            DB::transaction(function () use ($subscription, $schedule) {
                Subscription::lockForUpdate()->find($subscription->id)?->update([
                    'scheduled_addons' => $schedule,
                    'scheduled_addons_at' => $schedule === null ? null : $subscription->current_period_end,
                    'scheduled_addon_unit_cents' => null,
                ]);
            });

            $this->billing->addonsChanged($subscription);
        }

        $result = null;

        if ($quote['increases']) {
            $result = $this->billing->createAddonInvoice(
                $subscription->fresh(),
                $quote['charge_now_cents'],
                Carbon::parse($quote['covered_until']),
                [
                    'addons' => collect($quote['increases'])->mapWithKeys(fn ($line) => [$line['key'] => $line['to']])->all(),
                    'lines' => $quote['increases'],
                ],
                (string) $payment['method'],
                $payment,
            );
        }

        return [
            'state' => $this->state($tenant->fresh()),
            'invoice' => $result['invoice'] ?? null,
            'payment' => $result ? array_filter([
                'status' => $result['status'],
                'client_secret' => $result['client_secret'] ?? null,
            ]) : null,
        ];
    }

    /**
     * An extras invoice was paid: the units it bought are added.
     *
     * Runs inside the payment transaction. Only on the subscription it was
     * bought for, and only while that is still the workspace's plan — money
     * that arrives for a plan already replaced is logged for support rather
     * than added to a row nobody is on.
     */
    public function handleInvoicePaid(Invoice $invoice): void
    {
        $subscription = Subscription::lockForUpdate()->find($invoice->subscription_id);

        if (($invoice->meta['applied_at'] ?? null) !== null) {
            return;
        }

        $tenant = $subscription?->tenant;

        if (! $subscription
            || $subscription->status === SubscriptionStatus::Cancelled
            || (int) $tenant?->current_subscription_id !== (int) $subscription->id) {
            Log::warning('Extras paid for a subscription that is no longer current', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $invoice->subscription_id,
            ]);

            return;
        }

        $held = $subscription->addonQuantities();
        // A reduction waiting for the period end. Not a downgrade's units,
        // which the plan it moves to prices itself.
        $scheduled = $this->scheduledReduction($subscription);

        foreach (Addon::clean($invoice->meta['addons'] ?? []) as $key => $quantity) {
            $held[$key] = max($held[$key] ?? 0, $quantity);

            // Bought for the next period as well: a reduction scheduled before
            // this purchase must not take the new units straight back.
            if ($scheduled !== null) {
                $scheduled[$key] = max($scheduled[$key] ?? 0, $quantity);
            }
        }

        // The price each new unit was quoted at becomes the price it renews at.
        $prices = $subscription->addon_unit_cents ?? [];

        foreach ($invoice->meta['lines'] ?? [] as $line) {
            if (isset($line['key'], $line['unit_cents']) && ! isset($prices[$line['key']])) {
                $prices[$line['key']] = (int) $line['unit_cents'];
            }
        }

        $subscription->update([
            'addons' => $held ?: null,
            'addon_unit_cents' => $prices ?: null,
            ...($scheduled === null ? [] : [
                'scheduled_addons' => $scheduled != $held ? $scheduled : null,
                'scheduled_addons_at' => $scheduled != $held ? $subscription->scheduled_addons_at : null,
            ]),
        ]);

        $invoice->update(['meta' => array_merge($invoice->meta ?? [], ['applied_at' => now()->toIso8601String()])]);

        DB::afterCommit(fn () => $this->billing->addonsChanged($subscription));
    }

    /**
     * Why extras cannot be bought right now, or null.
     *
     * - `no_plan`: there is no plan to add to.
     * - `manual`: granted by the team, with no payment behind it — capacity
     *   there is the team's to change (Back Office → Entitlements).
     * - `inactive`: unpaid, ended, or with no period running to prorate.
     * - `pending_change`: an upgrade waits for its first payment; units added
     *   to the plan it replaces would vanish with it.
     * - `scheduled_change`: a cheaper plan starts when this period ends, and it
     *   prices its extras itself — keep the current plan to change them here.
     * - `not_sold`: this plan sells no extras in this country.
     */
    public function unavailableReason(Tenant $tenant, ?Subscription $subscription): ?string
    {
        if (! $subscription) {
            return 'no_plan';
        }

        if ($subscription->payment_method === PaymentMethod::Manual || $subscription->status === SubscriptionStatus::Manual) {
            return 'manual';
        }

        if (! $subscription->isUsable()
            || $subscription->status === SubscriptionStatus::Cancelled
            || $subscription->cycleTotalCents() <= 0
            || ! $subscription->current_period_end
            || ! $subscription->current_period_end->isFuture()) {
            return 'inactive';
        }

        if ($this->billing->pendingChangeFor($tenant)) {
            return 'pending_change';
        }

        if ($subscription->hasScheduledChange()) {
            return 'scheduled_change';
        }

        if ($this->unitPrices($subscription) === []) {
            return 'not_sold';
        }

        return null;
    }

    /**
     * One unit of each extra this subscription can hold: the price frozen
     * when it was bought, else the plan's price today for this cycle. Only
     * for limits the plan sets.
     *
     * @return array<string, int>
     */
    public function unitPrices(Subscription $subscription): array
    {
        $included = $this->includedQuotas($subscription);
        $live = $subscription->plan?->addonUnitPrices($subscription->tenant?->market_code, $subscription->billing_cycle) ?? [];
        $frozen = $subscription->addon_unit_cents ?? [];
        $prices = [];

        foreach (Addon::cases() as $addon) {
            if (! $addon->limitedBy($included)) {
                continue;
            }

            $price = $frozen[$addon->value] ?? $live[$addon->value] ?? null;

            if (is_numeric($price) && (int) $price > 0) {
                $prices[$addon->value] = (int) $price;
            }
        }

        return $prices;
    }

    /**
     * Whether one more unit of this extra can be bought right now — what a
     * "limit reached" answer offers instead of only refusing.
     */
    public function canBuy(Tenant $tenant, Addon $addon): bool
    {
        $subscription = $tenant->currentSubscription?->loadMissing('plan');

        return $this->unavailableReason($tenant, $subscription) === null
            && isset($this->unitPrices($subscription)[$addon->value])
            && ($subscription->addonQuantities()[$addon->value] ?? 0) < Addon::MAX_UNITS;
    }

    protected function eligibleSubscription(Tenant $tenant): Subscription
    {
        $subscription = $tenant->currentSubscription?->loadMissing('plan');
        $reason = $this->unavailableReason($tenant, $subscription);

        if ($reason !== null) {
            throw ValidationException::withMessages(['addons' => __(match ($reason) {
                'no_plan' => 'Choose a plan before adding extras.',
                'manual' => 'This plan was set up by our team. Talk to us to change its limits.',
                'pending_change' => 'Finish or drop the plan change you started before adding extras.',
                'scheduled_change' => 'A plan change is scheduled. Keep the current plan to change its extras.',
                'not_sold' => 'This plan has no extras for sale.',
                default => 'Extras can be added once the plan is paid and active.',
            })]);
        }

        return $subscription;
    }

    /**
     * The totals asked for, for every extra this subscription can hold. A key
     * left out keeps what the next period already holds (a scheduled reduction
     * stays scheduled), so a request about one extra never touches the other.
     *
     * @param  array<string, mixed>  $requested
     * @param  array<string, int>  $prices
     * @return array<string, int>
     */
    protected function targets(Subscription $subscription, array $requested, array $prices): array
    {
        $current = $this->scheduledReduction($subscription) ?? $subscription->addonQuantities();
        $targets = [];

        foreach (array_keys($prices) as $key) {
            $value = array_key_exists($key, $requested) ? (int) $requested[$key] : ($current[$key] ?? 0);
            $targets[$key] = max(0, min($value, Addon::MAX_UNITS));
        }

        return $targets;
    }

    /**
     * The units held from the next period on, before any payment lands: an
     * increase is not part of it until paid — otherwise a Pix left unpaid
     * would still raise the renewal.
     *
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $held
     * @return array<string, int>
     */
    protected function nextPeriodUnits(array $targets, array $held): array
    {
        $next = $held;

        foreach ($targets as $key => $target) {
            $next[$key] = min($target, $held[$key] ?? 0);
        }

        return array_filter($next, fn (int $quantity) => $quantity > 0);
    }

    /**
     * A reduction waiting for the end of the paid period, or null. Not a
     * downgrade's units: those belong to the plan it moves to.
     *
     * @return array<string, int>|null
     */
    protected function scheduledReduction(?Subscription $subscription): ?array
    {
        if (! $subscription?->hasScheduledAddons() || $subscription->hasScheduledChange()) {
            return null;
        }

        return $subscription->scheduledAddonQuantities();
    }

    /**
     * The plan's own limits, without the extras on top.
     *
     * @return array<string, int|null>
     */
    protected function includedQuotas(?Subscription $subscription): array
    {
        if (! $subscription) {
            return [];
        }

        return array_filter(
            $subscription->quotas_snapshot ?? $subscription->plan?->quotas ?? [],
            fn ($value) => $value !== null,
        );
    }

    /** How much of what the extra raises is in use. */
    protected function used(Tenant $tenant, Addon $addon): int
    {
        return match ($addon) {
            Addon::Agent => $tenant->users()->count(),
            Addon::WhatsappConnection => $this->gate->connectionsUsed($tenant, Quota::MaxWhatsappConnections),
        };
    }
}
