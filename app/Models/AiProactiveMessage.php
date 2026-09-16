<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message the AI Hub pushed into a conversation — idempotency record and
 * audit trail at once. See AiProactiveMessageService.
 */
class AiProactiveMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'api_key_id',
        'idempotency_key',
        'conversation_id',
        'ai_hub_agent_id',
        'message_id',
        'status',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class, 'ai_hub_agent_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
