<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A purchased API Way instance (core UUID + metadata). Linked to at most one
 * Connection; unlinked instances form the pool offered in the connect wizard.
 */
class ApiwayInstance extends Model
{
    protected $fillable = [
        'tenant_id',
        'apiway_subscription_id',
        'provider_instance_id',
        'name',
        'ip_address',
        'status',
        'connection_id',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription()
    {
        return $this->belongsTo(ApiwaySubscription::class, 'apiway_subscription_id');
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class);
    }

    /** Unlinked instances whose subscription is still live. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->whereNull('connection_id')
            ->whereHas('subscription', fn (Builder $q) => $q->live());
    }

    public function isUsable(): bool
    {
        return $this->subscription?->isLive() ?? false;
    }

    public function isAvailable(): bool
    {
        return $this->connection_id === null && $this->isUsable();
    }
}
