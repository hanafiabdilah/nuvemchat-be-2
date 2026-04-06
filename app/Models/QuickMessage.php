<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class QuickMessage extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'shortcut',
        'message',
    ];

    /**
     * Get the tenant that owns the quick message.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user that owns the quick message (if user-specific).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include tenant-level quick messages.
     */
    public function scopeTenantLevel(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * Scope a query to only include user-specific quick messages.
     */
    public function scopeUserSpecific(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to include quick messages available for a specific user.
     * This includes both tenant-level and user-specific messages.
     */
    public function scopeAvailableForUser(Builder $query, int $userId, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId)
            ->where(function ($query) use ($userId) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $userId);
            });
    }
}
