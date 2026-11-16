<?php

namespace App\Models;

use App\Enums\Affiliate\CommissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    protected $fillable = [
        'affiliate_id',
        'tenant_id',
        'invoice_id',
        'reversal_of_id',
        'base_cents',
        'percent',
        'amount_cents',
        'currency',
        'status',
        'available_at',
        'payout_id',
        'paid_at',
        'reversed_at',
    ];

    protected $casts = [
        'status' => CommissionStatus::class,
        'base_cents' => 'integer',
        'percent' => 'integer',
        'amount_cents' => 'integer',
        'available_at' => 'datetime',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /** Pending and past its holding period: what the next payout pays. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', CommissionStatus::Pending->value)->where('available_at', '<=', now());
    }

    /** Pending and still inside its holding period. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->where('status', CommissionStatus::Pending->value)->where('available_at', '>', now());
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
