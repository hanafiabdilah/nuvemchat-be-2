<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHubKnowledge extends Model
{
    protected $table = 'ai_hub_knowledge';

    protected $fillable = [
        'ai_hub_agent_id',
        'hub_knowledge_id',
        'title',
        'content',
        'tags',
        'metadata',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
    ];

    public function aiHubAgent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class);
    }
}
