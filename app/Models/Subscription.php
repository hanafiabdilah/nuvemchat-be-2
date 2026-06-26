<?php

namespace App\Models;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'payment_method',
        'billing_cycle',
        'price_cents',
        'quotas_snapshot',
        'features_snapshot',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'grace_ends_at',
        'cancel_at_period_end',
        'cancelled_at',
        'mp_preapproval_id',
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
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'grace_ends_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
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
     * Effective entitlements — prefer the snapshot, fall back to the live plan.
     *
     * @return array{quotas: array, features: array}
     */
    public function entitlements(): array
    {
        return [
            'quotas' => $this->quotas_snapshot ?? $this->plan?->quotas ?? [],
            'features' => $this->features_snapshot ?? $this->plan?->features ?? [],
        ];
    }
}
