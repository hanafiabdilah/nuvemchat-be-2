<?php

namespace App\Http\Resources\TrainedAgent;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Back Office view: the whole blueprint, prompt and training included.
 * Only reachable behind `bo.trained-agents.manage` — see
 * TrainedAgentCatalogResource for the redacted shape tenants get.
 *
 * @mixin \App\Models\TrainedAgentBlueprint
 */
class TrainedAgentBlueprintResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'trained_agent_category_id' => $this->trained_agent_category_id,
            'category' => new TrainedAgentCategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'icon' => $this->icon,
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
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'sort_order' => $this->sort_order,
            'hires_count' => $this->whenCounted('hires'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
