<?php

namespace App\Models;

use App\Enums\Billing\Addon;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'plan_id',
        // An upgrade waiting for its first charge: the subscription it takes
        // over from once paid — see BillingService::completePlanSwitch().
        'replaces_subscription_id',
        // Unused value of the replaced plan paid into the balance once this one
        // is active, where the gateway could not discount the first charge.
        'proration_balance_cents',
        'status',
        'payment_method',
        // A card only: false = paid by hand each cycle (saved card + CVV).
        'auto_renew',
        // Which gateway holds the stored instrument (null = payment service).
        'gateway',
        'billing_cycle',
        'price_cents',
        // Snapshotted beside the price. A frozen amount whose unit is read live
        // off the plan is not frozen — see BillingService::chargeRenewal().
        'currency',
        'quotas_snapshot',
        'features_snapshot',
        // Extras bought on top of the plan: units by add-on key, and the
        // per-cycle price of one unit frozen when it was bought. See Addon.
        'addons',
        'addon_unit_cents',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'grace_ends_at',
        'cancel_at_period_end',
        'cancelled_at',
        // A downgrade: the terms this row moves to at `scheduled_change_at`.
        'scheduled_plan_id',
        'scheduled_price_cents',
        'scheduled_billing_cycle',
        'scheduled_change_at',
        // The extras held from `scheduled_addons_at` on — a reduction, or the
        // units a scheduled downgrade carries — and their price on that plan.
        'scheduled_addons',
        'scheduled_addons_at',
        'scheduled_addon_unit_cents',
        'payment_instrument_id',
        'payment_customer_id',
        // {brand, last_four, exp_month, exp_year} of the card that renews it.
        'payment_card',
        'manual_granted_by',
        'manual_note',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'payment_method' => PaymentMethod::class,
        'billing_cycle' => BillingCycle::class,
        'price_cents' => 'integer',
        'quotas_snapshot' => 'array',
        'features_snapshot' => 'array',
        'addons' => 'array',
        'addon_unit_cents' => 'array',
        'scheduled_addons' => 'array',
        'scheduled_addons_at' => 'datetime',
        'scheduled_addon_unit_cents' => 'array',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'grace_ends_at' => 'datetime',
        'due_reminder_sent_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'cancelled_at' => 'datetime',
        'proration_balance_cents' => 'integer',
        'scheduled_price_cents' => 'integer',
        'scheduled_billing_cycle' => BillingCycle::class,
        'scheduled_change_at' => 'datetime',
        'payment_card' => 'array',
        'auto_renew' => 'boolean',
    ];

    /**
     * A card subscription the customer pays by hand each cycle: an invoice is
     * raised, and they settle it with a saved card and its security code.
     * Nothing is charged with nobody at a screen.
     */
    public function paysCardPerCycle(): bool
    {
        return $this->payment_method === PaymentMethod::Card && $this->auto_renew === false;
    }

    /**
     * The payment methods whose invoices this subscription pays by hand.
     * Pix and a hosted checkout always; a card only when it does not renew
     * by itself.
     *
     * @return list<string>
     */
    public function perCycleMethods(): array
    {
        return array_values(array_filter([
            PaymentMethod::Pix->value,
            PaymentMethod::Checkout->value,
            $this->paysCardPerCycle() ? PaymentMethod::Card->value : null,
        ]));
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function scheduledPlan()
    {
        return $this->belongsTo(Plan::class, 'scheduled_plan_id');
    }

    public function replaces()
    {
        return $this->belongsTo(self::class, 'replaces_subscription_id');
    }

    public function hasScheduledChange(): bool
    {
        return $this->scheduled_plan_id !== null && $this->scheduled_change_at !== null;
    }

    /**
     * The plan's own charges: first payment and every renewal.
     *
     * ⚠️ Extras bought mid-period are kept apart (addonInvoices()). Every rule
     * that reads "this subscription's invoices" — the open renewal, the cycle
     * already billed, the Pix to void — means the plan's cycle charges, and an
     * extra's one-off payment mixed in would be read as one of them.
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class)->where('purpose', InvoicePurpose::Subscription->value);
    }

    /** Extras bought on top of the running plan, one invoice per purchase. */
    public function addonInvoices()
    {
        return $this->hasMany(Invoice::class)->where('purpose', InvoicePurpose::SubscriptionAddon->value);
    }

    /**
     * Units by add-on key — of what is held now, or of another stored set.
     *
     * @return array<string, int>
     */
    public function addonQuantities(?array $from = null): array
    {
        return Addon::clean($from ?? $this->addons);
    }

    public function hasScheduledAddons(): bool
    {
        return $this->scheduled_addons_at !== null;
    }

    /**
     * The units held from `scheduled_addons_at` on. Empty — not what is held
     * now — when the schedule takes every extra away.
     *
     * @return array<string, int>
     */
    public function scheduledAddonQuantities(): array
    {
        return Addon::clean($this->scheduled_addons ?? []);
    }

    /**
     * What one unit costs per cycle on this subscription: the frozen price, or
     * nothing when none was frozen (a unit is never held without one).
     */
    public function addonUnitCents(Addon $addon, ?array $prices = null): int
    {
        $cents = ($prices ?? $this->addon_unit_cents ?? [])[$addon->value] ?? null;

        return is_numeric($cents) ? max(0, (int) $cents) : 0;
    }

    /**
     * The extras' share of one cycle.
     *
     * @param  array<string, mixed>|null  $units  Held now when null.
     * @param  array<string, mixed>|null  $prices  The frozen prices when null.
     */
    public function addonsCents(?array $units = null, ?array $prices = null): int
    {
        $total = 0;

        foreach ($this->addonQuantities($units) as $key => $quantity) {
            $total += $quantity * $this->addonUnitCents(Addon::from($key), $prices);
        }

        return $total;
    }

    /** The plan and its extras: what one cycle of this subscription costs. */
    public function cycleTotalCents(): int
    {
        return (int) $this->price_cents + $this->addonsCents();
    }

    /**
     * Whether this subscription currently grants platform access.
     */
    public function isUsable(): bool
    {
        if (! $this->status->isUsable()) {
            return false;
        }

        // Manual grants with no end date are unlimited.
        if ($this->status === SubscriptionStatus::Manual && $this->current_period_end === null) {
            return true;
        }

        // Grace keeps access until grace_ends_at; others until period end.
        $deadline = $this->grace_ends_at ?? $this->current_period_end;

        return $deadline === null || $deadline->isFuture();
    }

    /**
     * Effective entitlements — prefer the snapshot, fall back to the live plan,
     * and raise each limit by the extras bought for it.
     *
     * @return array{quotas: array, features: array}
     */
    public function entitlements(): array
    {
        $quotas = $this->quotas_snapshot ?? $this->plan?->quotas ?? [];

        // The extras bought on top. Only onto a limit the plan sets — an
        // unlimited quota has nothing to add to.
        foreach ($this->addonQuantities() as $key => $quantity) {
            $quota = Addon::from($key)->quota()->value;

            if (isset($quotas[$quota])) {
                $quotas[$quota] = (int) $quotas[$quota] + $quantity;
            }
        }

        return [
            'quotas' => $quotas,
            'features' => $this->features_snapshot ?? $this->plan?->features ?? [],
        ];
    }
}
