<?php

namespace App\Models;

use App\Enums\Gallery\StorageRentalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The extra gigabytes a workspace rents on top of its plan.
 *
 * One row per tenant, edited rather than replaced: the amount is a quantity,
 * not a series of purchases, and a history of every size it has been is worth
 * less than a single number nobody can add up wrongly. What was charged and
 * when lives in the credit ledger, which is where a customer looks for it.
 */
class GalleryStorageRental extends Model
{
    protected $fillable = [
        'tenant_id',
        'gb',
        'pending_gb',
        'price_per_gb_cents',
        'currency',
        'status',
        'started_at',
        'renews_at',
        'cancelled_at',
        'renewal_reminder_sent_at',
        'meta',
    ];

    protected $casts = [
        'status' => StorageRentalStatus::class,
        'gb' => 'integer',
        'pending_gb' => 'integer',
        'price_per_gb_cents' => 'integer',
        'started_at' => 'datetime',
        'renews_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StorageRentalStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status->isActive() && $this->gb > 0;
    }

    /** What the next full month costs at the price on the row. */
    public function monthlyCents(): int
    {
        return $this->gb * $this->price_per_gb_cents;
    }

    /**
     * The size this rental will be after the next renewal.
     *
     * A scheduled reduction is not applied until then, so this is the number to
     * show next to the date and never the one to enforce today.
     */
    public function effectiveGbAtRenewal(): int
    {
        return $this->pending_gb ?? $this->gb;
    }
}
