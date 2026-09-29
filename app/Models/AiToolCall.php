<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One tool the AI Hub asked us to run. See AiToolCallService. */
class AiToolCall extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'api_key_id',
        'idempotency_key',
        'conversation_id',
        'flow_state_id',
        'flow_node_id',
        'ai_hub_agent_id',
        'hub_run_id',
        'tool',
        'arguments',
        'status',
        'result',
        'error',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'duration_ms' => 'integer',
    ];
}
