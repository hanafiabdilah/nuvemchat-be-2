<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A URL of the workspace's that Pingly posts lead events to, signed with this
 * endpoint's secret. See App\Services\Webhooks\WebhookDispatcher.
 */
class WebhookEndpoint extends Model
{
    public const MAX_PER_TENANT = 5;

    public const SECRET_PREFIX = 'whsec_';

    protected $fillable = [
        'tenant_id',
        'url',
        'events',
        'secret',
        'is_active',
        'created_by',
        'last_delivery_at',
        'last_response_status',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'last_delivery_at' => 'datetime',
        'last_response_status' => 'integer',
    ];

    public static function newSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(40);
    }

    public function subscribes(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }

    /** Enough of the secret to tell two apart, never enough to use. */
    public function secretHint(): string
    {
        return self::SECRET_PREFIX.'…'.substr((string) $this->secret, -4);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
