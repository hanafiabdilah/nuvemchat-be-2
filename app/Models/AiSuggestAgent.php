<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "Respond with AI" agent: the provider credentials (and later, the style/
 * persona) used to draft reply suggestions. Tenants manage several and link
 * each connection to one — distinct from AiHubAgent (the flow AI agent).
 */
class AiSuggestAgent extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'provider',
        'api_key',
        'model',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }
}
