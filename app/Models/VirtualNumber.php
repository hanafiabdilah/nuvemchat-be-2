<?php

namespace App\Models;

use App\Enums\Numbers\VirtualNumberStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A phone number rented from API Way and resold to one tenant.
 *
 * Local mirror of an upstream monthly subscription: API Way owns the number and
 * the renewal date, this row owns who it belongs to and what they paid. There
 * is no upstream "renew" call — the subscription renews itself and bills the
 * platform — so `renews_at` is a deadline, not a button: by then the tenant has
 * either been charged or the number has to be cancelled.
 */
class VirtualNumber extends Model
{
    protected $fillable = [
        'tenant_id',
        'provider_number_id',
        'msisdn',
        'app',
        'ddd',
        'region',
        'status',
        'cost_cents',
        'price_cents',
        'currency',
        'purchased_at',
        'renews_at',
        'cancelled_at',
        'renewal_reminder_sent_at',
        'last_synced_at',
        'last_message_at',
        'meta',
    ];

    protected $casts = [
        'status' => VirtualNumberStatus::class,
        'provider_number_id' => 'integer',
        'cost_cents' => 'integer',
        'price_cents' => 'integer',
        'purchased_at' => 'datetime',
        'renews_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'renewal_reminder_sent_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_message_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function messages()
    {
        return $this->hasMany(VirtualNumberMessage::class)->orderByDesc('received_at')->orderByDesc('id');
    }

    /** Numbers the platform is still paying for. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', VirtualNumberStatus::liveValues());
    }

    /** Live and confirmed upstream — the only rows an SMS can arrive on. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', VirtualNumberStatus::Active->value);
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /** Why this number ended, when it was not the tenant's own doing. */
    public function cancelReason(): ?string
    {
        return $this->meta['cancel_reason'] ?? null;
    }
}
