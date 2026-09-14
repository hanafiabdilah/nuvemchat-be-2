<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lead received through the public API — see LeadIntakeService.
 */
class LeadIntake extends Model
{
    protected $fillable = [
        'tenant_id',
        'api_key_id',
        'reference',
        'connection_id',
        'contact_id',
        'lead_id',
        'conversation_id',
        'opening_status',
        'payload',
        'result',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
