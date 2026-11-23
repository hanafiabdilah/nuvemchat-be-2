<?php

namespace App\Http\Resources\Incident;

use App\Models\IncidentTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncidentTemplate */
class IncidentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'match_mode' => $this->match_mode->value,
            'reply_mode' => $this->reply_mode->value,
            'ai_hub_agent_id' => $this->ai_hub_agent_id,
            'ai_brief' => $this->ai_brief,
            'keywords' => array_values((array) ($this->keywords ?? [])),
            'exclude_keywords' => array_values((array) ($this->exclude_keywords ?? [])),
            'message' => $this->message,
            'media_url' => $this->media_url,
            'media_type' => $this->media_type,
            'resolution_message' => $this->resolution_message,
            'duration_minutes' => $this->duration_minutes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
