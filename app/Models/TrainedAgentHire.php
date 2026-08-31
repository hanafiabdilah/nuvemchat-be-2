<?php

namespace App\Models;

use App\Enums\TrainedAgent\HireSource;
use App\Enums\TrainedAgent\HireStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One trained agent taken by one tenant. See the table migration for why this
 * row outlives both the blueprint and the forked agent.
 */
class TrainedAgentHire extends Model
{
    protected $fillable = [
        'tenant_id',
        'trained_agent_blueprint_id',
        'ai_hub_agent_id',
        'ai_hub_provider_credential_id',
        'external_ref',
        'source',
        'status',
        'agent_name',
        'price_cents',
        'currency',
        'blueprint_snapshot',
        'meta',
        'hired_at',
    ];

    protected $casts = [
        'source' => HireSource::class,
        'status' => HireStatus::class,
        'price_cents' => 'integer',
        'blueprint_snapshot' => 'array',
        'meta' => 'array',
        'hired_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(TrainedAgentBlueprint::class, 'trained_agent_blueprint_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class, 'ai_hub_agent_id');
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(AiHubProviderCredential::class, 'ai_hub_provider_credential_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Hires that occupy one of the plan's included slots. */
    public function scopeConsumingAllowance(Builder $query): Builder
    {
        return $query
            ->where('source', HireSource::Included->value)
            ->whereIn('status', [
                HireStatus::Provisioning->value,
                HireStatus::Active->value,
            ]);
    }

    /**
     * Purchases the platform owes someone something for: money captured and no
     * agent delivered, with no refund recorded yet.
     *
     * Presence, not value — the same reason ApiwaySubscription::needsAttention
     * gives: a boolean comparison across a JSON path does not behave the same
     * on MySQL and SQLite.
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query
            ->whereNotNull('meta->needs_refund')
            ->whereNull('meta->refund_settled_at');
    }

    public function needsAttention(): bool
    {
        $meta = $this->meta ?? [];

        return ! empty($meta['needs_refund']) && empty($meta['refund_settled_at']);
    }
}
