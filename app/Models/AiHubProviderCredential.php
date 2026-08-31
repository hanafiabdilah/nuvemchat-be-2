<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiHubProviderCredential extends Model
{
    protected $fillable = [
        'ai_hub_tenant_id',
        'ai_token_pool_key_id',
        'hub_provider_credential_id',
        'provider',
        'name',
        'key_preview',
        'default_model',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function aiHubTenant(): BelongsTo
    {
        return $this->belongsTo(AiHubTenant::class);
    }

    /**
     * The platform key this credential was minted from, when it is rented.
     * Null for a credential the tenant created with their own API key.
     */
    public function poolKey(): BelongsTo
    {
        return $this->belongsTo(AiTokenPoolKey::class, 'ai_token_pool_key_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(AiHubAgent::class);
    }

    /**
     * Whether this credential is the platform's key, rented to the tenant.
     *
     * The single question every guard asks: a rented credential may be picked
     * by an agent like any other, but the tenant may not re-key it, rename the
     * secret behind it or delete it — the row is our record of a key we own and
     * pay for, not theirs.
     */
    public function isRented(): bool
    {
        return $this->ai_token_pool_key_id !== null;
    }

    public function scopeRented(Builder $query): Builder
    {
        return $query->whereNotNull('ai_token_pool_key_id');
    }

    public function scopeOwned(Builder $query): Builder
    {
        return $query->whereNull('ai_token_pool_key_id');
    }
}
