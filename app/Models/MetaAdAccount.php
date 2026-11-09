<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Meta ad account a workspace shared with Pingly, and the token that
 * reads its costs. See App\Services\MetaAds\MetaAdsService.
 */
class MetaAdAccount extends Model
{
    /** Reads fine. */
    public const STATUS_ACTIVE = 'active';

    /** Meta refused the token (expired, revoked, the person removed the app): only a new login fixes it. */
    public const STATUS_REAUTH = 'reauth_required';

    /** The last sync failed for another reason; the next one may well work. */
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'tenant_id',
        'account_id',
        'name',
        'currency',
        'timezone',
        'access_token',
        'fb_user_id',
        'connected_by_user_id',
        'enabled',
        'status',
        'last_error',
        'last_synced_at',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function insights(): HasMany
    {
        return $this->hasMany(MetaAdInsight::class);
    }
}
