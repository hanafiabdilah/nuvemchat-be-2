<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's prepaid balance.
 *
 * Built for AI runs on rented platform keys and since widened to everything the
 * workspace buys as it goes — API Way instances, trained agent hires — which is
 * why it is one wallet and not one per product: a customer holding three
 * balances has to guess which one to top up before they can do anything.
 *
 * The row is created lazily, on the first read or movement, so workspaces that
 * never buy anything never get one.
 */
class CreditWallet extends Model
{
    protected $fillable = [
        'tenant_id',
        'balance_cents',
        'currency',
        'low_balance_notified_at',
    ];

    protected $casts = [
        'balance_cents' => 'integer',
        'low_balance_notified_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'tenant_id', 'tenant_id');
    }

    /**
     * Whether there is anything left to spend.
     *
     * Strictly positive, not "not negative": a run's price is unknown until it
     * has run, so allowing a run at exactly zero would guarantee an overdraft
     * every time rather than only when the last run happened to be expensive.
     */
    public function hasCredit(): bool
    {
        return $this->balance_cents > 0;
    }
}
