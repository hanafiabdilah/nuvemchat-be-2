<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHubAgentProfile extends Model
{
    protected $fillable = [
        'ai_hub_agent_id',
        'language',
        'tone',
        'response_style',
        'instructions',
        'limits',
        'metadata',
    ];

    protected $casts = [
        'instructions' => 'array',
        'limits' => 'array',
        'metadata' => 'array',
    ];

    public function aiHubAgent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class);
    }
}
