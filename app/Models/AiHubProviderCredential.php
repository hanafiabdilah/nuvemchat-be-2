<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiHubProviderCredential extends Model
{
    protected $fillable = [
        'ai_hub_tenant_id',
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

    public function agents(): HasMany
    {
        return $this->hasMany(AiHubAgent::class);
    }
}
