<?php

namespace App\Http\Resources\TrainedAgent;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tenant-facing view of a blueprint.
 *
 * It deliberately does NOT carry `system_prompt`, the knowledge bodies or the
 * training examples: that writing IS the product being sold, and a catalog
 * that ships it to the browser before payment has given it away — the /hire
 * endpoint would be the slowest way to obtain something already sitting in the
 * network tab. What a buyer needs to decide is how much of it there is and
 * what it covers, so counts and titles go out and the content stays home.
 *
 * After the hire, none of this applies: the fork is an ordinary agent of
 * theirs, fully readable and editable on the AI Agents page.
 *
 * @mixin \App\Models\TrainedAgentBlueprint
 */
class TrainedAgentCatalogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'icon' => $this->icon,
            'model' => $this->model,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
                'icon' => $this->category->icon,
            ]),
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'is_free' => $this->isFree(),
            // What is inside, without handing over what is inside.
            'contents' => [
                'knowledge' => count($this->knowledge ?? []),
                'skills' => count($this->skills ?? []),
                'training_examples' => count($this->training_examples ?? []),
                'has_profile' => ! empty($this->profile),
            ],
            'knowledge_titles' => collect($this->knowledge ?? [])
                ->pluck('title')->filter()->take(8)->values(),
            'skill_names' => collect($this->skills ?? [])
                ->pluck('name')->filter()->take(8)->values(),
        ];
    }
}
