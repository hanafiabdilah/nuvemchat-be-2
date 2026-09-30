<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card kept at the gateway for this workspace. See the migration for what is
 * (and is never) stored here.
 */
class SavedCard extends Model
{
    protected $fillable = [
        'tenant_id',
        'gateway',
        'customer_id',
        'customer_email',
        'card_id',
        'brand',
        'payment_type',
        'issuer_id',
        'first_six',
        'last_four',
        'exp_month',
        'exp_year',
        'holder_name',
        'created_by',
        'last_used_at',
    ];

    protected $casts = [
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Past its expiry month: the gateway will refuse it, so it is not offered. */
    public function isExpired(): bool
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        return now()->startOfMonth()->greaterThan(
            \Illuminate\Support\Carbon::create($this->exp_year, $this->exp_month, 1),
        );
    }
}
