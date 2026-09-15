<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event sent (or being sent) to one endpoint, with the outcome of its last
 * attempt.
 */
class WebhookDelivery extends Model
{
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $fillable = [
        'webhook_endpoint_id',
        'event_id',
        'event',
        'payload',
        'status',
        'attempts',
        'response_status',
        'response_body',
        'error',
        'delivered_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'response_status' => 'integer',
        'delivered_at' => 'datetime',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
