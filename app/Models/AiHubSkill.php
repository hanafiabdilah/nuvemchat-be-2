<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHubSkill extends Model
{
    protected $fillable = [
        'ai_hub_agent_id',
        'hub_skill_id',
        'name',
        'description',
        'instructions',
        'metadata',
    ];

    protected $casts = [
        'instructions' => 'array',
        'metadata' => 'array',
    ];

    public function aiHubAgent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class);
    }
}
