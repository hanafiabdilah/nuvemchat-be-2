<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One media file a flow asked the AI Hub to generate. See the migration.
 */
class AiMediaGeneration extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'flow_state_id',
        'flow_node_id',
        'ai_hub_provider_credential_id',
        'external_id',
        'hub_generation_id',
        'type',
        'status',
        'provider',
        'model',
        'prompt',
        'request',
        'path',
        'mime_type',
        'size_bytes',
        'cost_usd',
        'error_code',
        'error_message',
        'polls',
        'completed_at',
    ];

    protected $casts = [
        'request' => 'array',
        'cost_usd' => 'float',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiHubProviderCredential::class, 'ai_hub_provider_credential_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
