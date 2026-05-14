<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHubTrainingExample extends Model
{
    protected $fillable = [
        'ai_hub_agent_id',
        'hub_example_id',
        'type',
        'input',
        'expected_output',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function aiHubAgent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class);
    }
}
