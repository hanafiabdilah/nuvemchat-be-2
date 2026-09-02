<?php

namespace App\Models;

use App\Enums\AiToken\TokenPoolKeyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One provider API key owned by the platform and rented out to tenants.
 *
 * `api_key` is the only raw provider secret this application stores. It is
 * encrypted at rest by the cast below, it is hidden from array/JSON
 * serialisation so an accidental `->toArray()` cannot leak it, and no Resource
 * exposes it. The Back Office shows `key_preview` and nothing more — an admin
 * who needs the real key has it at the provider.
 */
class AiTokenPoolKey extends Model
{
    protected $fillable = [
        'provider',
        'label',
        'api_key',
        'key_preview',
        'default_model',
        'status',
        'weight',
        'max_tenants',
        'meta',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'status' => TokenPoolKeyStatus::class,
        'weight' => 'integer',
        'max_tenants' => 'integer',
        'meta' => 'array',
    ];

    /**
     * Belt and braces on top of "no Resource exposes it": this model is a
     * plausible thing to dump into a log context or return from a quick admin
     * endpoint, and both would print the secret.
     */
    protected $hidden = ['api_key'];

    /**
     * The per-tenant hub credentials minted from this key. One row per tenant
     * renting it — a hub credential belongs to a hub tenant, so the same secret
     * is registered once per workspace.
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(AiHubProviderCredential::class, 'ai_token_pool_key_id');
    }

    /** Keys that may take on a new tenant right now. */
    public function scopeAvailableFor(Builder $query, string $provider): Builder
    {
        return $query
            ->where('provider', strtoupper($provider))
            ->where('status', TokenPoolKeyStatus::Active->value)
            ->where('weight', '>', 0)
            ->where(fn (Builder $q) => $q
                ->whereNull('max_tenants')
                ->orWhereRaw(
                    '(select count(*) from ai_hub_provider_credentials'
                    . ' where ai_hub_provider_credentials.ai_token_pool_key_id = ai_token_pool_keys.id)'
                    . ' < ai_token_pool_keys.max_tenants'
                ));
    }

    /**
     * The last four characters, in the shape the hub returns for a tenant's own
     * key so the two look alike in the Back Office.
     */
    public static function previewFor(string $apiKey): string
    {
        return '••••' . substr($apiKey, -4);
    }
}
