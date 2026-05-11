<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiHubTenant extends Model
{
    protected $fillable = [
        'tenant_id',
        'hub_tenant_id',
        'external_id',
        'name',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(AiHubApiKey::class);
    }

    public function activeApiKey(): HasOne
    {
        return $this->hasOne(AiHubApiKey::class)
            ->where('status', 'ACTIVE')
            ->latestOfMany();
    }

    public function providerCredentials(): HasMany
    {
        return $this->hasMany(AiHubProviderCredential::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(AiHubAgent::class);
    }
}
