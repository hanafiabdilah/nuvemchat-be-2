<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHubRun extends Model
{
    protected $fillable = [
        'tenant_id',
        'ai_hub_agent_id',
        'conversation_id',
        'flow_state_id',
        'flow_node_id',
        'message_id',
        'hub_run_id',
        'status',
        'provider',
        'model',
        'input_message',
        'output_message',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'total_tokens',
        'cost_usd',
        'cost_currency',
        'cost_breakdown',
        'error',
        'metadata',
        'started_at',
        'completed_at',
        'latency_ms',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'cost_usd' => 'decimal:8',
        'cost_breakdown' => 'array',
        'error' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'latency_ms' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function aiHubAgent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function flowState(): BelongsTo
    {
        return $this->belongsTo(FlowState::class);
    }

    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
