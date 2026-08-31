<?php

namespace App\Http\Resources\TrainedAgent;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TrainedAgentCategory */
class TrainedAgentCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'blueprints_count' => $this->whenCounted('blueprints'),
        ];
    }
}
