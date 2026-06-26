<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_type',
        'resource_id',
        'signature_valid',
        'payload',
        'processed_at',
        'dedupe_key',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
