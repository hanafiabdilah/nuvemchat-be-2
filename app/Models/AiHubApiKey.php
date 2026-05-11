<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHubApiKey extends Model
{
    protected $fillable = [
        'ai_hub_tenant_id',
        'hub_api_key_id',
        'name',
        'key_preview',
        'api_key',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key',
    ];

    public function aiHubTenant(): BelongsTo
    {
        return $this->belongsTo(AiHubTenant::class);
    }
}
