<?php

namespace App\Models;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of a ProxyBR partner subscription. ProxyBR owns provisioning
 * and the price catalog; this row owns billing state on our side. ProxyBR has
 * NO grace period — anything past expires_at is permanently revoked by their
 * hourly cron, so renewals must land before expiry.
 */
class ApiwaySubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'provider_subscription_id',
        'external_ref',
        'source',
        'cycle',
        'quantity',
        'unit_price_cents',
        'total_price_cents',
        'location_code',
        'status',
        'expires_at',
        'mp_preapproval_id',
        'renewal_reminder_sent_at',
        'last_synced_at',
        'meta',
    ];

    protected $casts = [
        'source' => ApiwaySubscriptionSource::class,
        'status' => ApiwaySubscriptionStatus::class,
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'total_price_cents' => 'integer',
        'expires_at' => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function instances()
    {
        return $this->hasMany(ApiwayInstance::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /** Live (non-terminal, not past expiry) subscriptions. */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                ApiwaySubscriptionStatus::Provisioning->value,
                ApiwaySubscriptionStatus::Active->value,
                ApiwaySubscriptionStatus::Suspended->value,
            ])
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Purchases the platform owes someone something for: money captured with
     * no instance delivered (and no refund recorded yet), or a paid purchase
     * parked while ProxyBR sits at its platform cap.
     *
     * Presence, not value: `needs_refund` and `capacity_hold` are only ever
     * written when true and unset when resolved, so a null json_extract means
     * "clear" on both MySQL and SQLite — a boolean comparison across a JSON
     * path does not survive that driver difference.
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q
                ->whereNotNull('meta->needs_refund')
                ->whereNull('meta->refund_settled_at'))
            ->orWhereNotNull('meta->capacity_hold'));
    }

    public function needsAttention(): bool
    {
        $meta = $this->meta ?? [];

        return (! empty($meta['needs_refund']) && empty($meta['refund_settled_at']))
            || isset($meta['capacity_hold']);
    }

    /** Subscriptions the partner API will accept a renew for. */
    public function scopeRenewable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ApiwaySubscriptionStatus::Active->value,
            ApiwaySubscriptionStatus::Suspended->value,
        ]);
    }

    public function isLive(): bool
    {
        return $this->status->isLive()
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
