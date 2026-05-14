<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiHubAgent extends Model
{
    protected $fillable = [
        'ai_hub_tenant_id',
        'ai_hub_provider_credential_id',
        'hub_agent_id',
        'external_id',
        'name',
        'description',
        'model',
        'system_prompt',
        'temperature',
        'max_tokens',
        'status',
        'handoff_rules',
        'metadata',
    ];

    protected $casts = [
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'handoff_rules' => 'array',
        'metadata' => 'array',
    ];

    public function aiHubTenant(): BelongsTo
    {
        return $this->belongsTo(AiHubTenant::class);
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(AiHubProviderCredential::class, 'ai_hub_provider_credential_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiHubRun::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(AiHubAgentProfile::class);
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(AiHubKnowledge::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(AiHubSkill::class);
    }

    public function trainingExamples(): HasMany
    {
        return $this->hasMany(AiHubTrainingExample::class);
    }
}
