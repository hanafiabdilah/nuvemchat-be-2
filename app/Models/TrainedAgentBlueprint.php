<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pre-trained agent the platform sells. Hiring one copies this into the
 * tenant's own workspace — see TrainedAgentService::fulfill().
 *
 * Soft-deleted rather than removed: hires point back at it, and a retired
 * blueprint still has to be nameable in a receipt.
 */
class TrainedAgentBlueprint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'trained_agent_category_id',
        'name',
        'slug',
        'tagline',
        'description',
        'icon',
        'model',
        'system_prompt',
        'temperature',
        'max_tokens',
        'handoff_rules',
        'profile',
        'knowledge',
        'skills',
        'training_examples',
        'price_cents',
        'currency',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected $casts = [
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'handoff_rules' => 'array',
        'profile' => 'array',
        'knowledge' => 'array',
        'skills' => 'array',
        'training_examples' => 'array',
        'price_cents' => 'integer',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TrainedAgentCategory::class, 'trained_agent_category_id');
    }

    public function hires(): HasMany
    {
        return $this->hasMany(TrainedAgentHire::class);
    }

    /** Blueprints a tenant may see and hire. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function isFree(): bool
    {
        return $this->price_cents <= 0;
    }

    /**
     * Everything the fork needs, frozen at hire time. Kept on the hire row so a
     * later edit (or retirement) of the blueprint cannot rewrite what was sold,
     * and so a failed fork can be retried against the original content.
     */
    public function snapshot(): array
    {
        return [
            'blueprint_id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category?->name,
            'model' => $this->model,
            'system_prompt' => $this->system_prompt,
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
            'handoff_rules' => $this->handoff_rules,
            'profile' => $this->profile,
            'knowledge' => $this->knowledge ?? [],
            'skills' => $this->skills ?? [],
            'training_examples' => $this->training_examples ?? [],
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
        ];
    }
}
