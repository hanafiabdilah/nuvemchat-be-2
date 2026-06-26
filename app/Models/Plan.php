<?php

namespace App\Models;

use App\Enums\Billing\BillingCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_cents',
        'currency',
        'billing_cycle',
        'trial_days',
        'quotas',
        'features',
        'is_active',
        'is_public',
        'sort_order',
        'mp_card_enabled',
        'mp_pix_enabled',
        'mp_preapproval_plan_id',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'billing_cycle' => BillingCycle::class,
        'trial_days' => 'integer',
        'quotas' => 'array',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
        'mp_card_enabled' => 'boolean',
        'mp_pix_enabled' => 'boolean',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}
