<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An API key as the dashboard lists it. Never the secret or its hash — the
 * plain key travels once, as `plain_key` beside this in the create response.
 */
class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creator = $this->getRelationValue('creator');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'hint' => $this->hint,
            'created_by' => $creator ? ['id' => $creator->id, 'name' => $creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
