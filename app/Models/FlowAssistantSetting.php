<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which key and model a workspace's flow assistant runs on, and the hub agent
 * that carries them. One row per workspace.
 *
 * Deliberately not an `ai_hub_agents` row — see the migration that creates the
 * table.
 */
class FlowAssistantSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'ai_hub_provider_credential_id',
        'model',
        'hub_agent_id',
        'agent_external_id',
        'agent_hash',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(AiHubProviderCredential::class, 'ai_hub_provider_credential_id');
    }

    /**
     * A key and a model are chosen, and the agent exists at the hub.
     *
     * The credential going away (its row deleted, which nulls the column) puts
     * the workspace back to "not set up" — the assistant asks for a key instead
     * of failing at the hub with a sentence about a credential nobody can see.
     */
    public function isConfigured(): bool
    {
        return $this->ai_hub_provider_credential_id !== null
            && filled($this->model)
            && filled($this->agent_external_id);
    }

    /** Runs are billed to the prepaid balance. */
    public function isRented(): bool
    {
        return (bool) $this->providerCredential?->isRented();
    }
}
