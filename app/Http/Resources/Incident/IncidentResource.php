<?php

namespace App\Http\Resources\Incident;

use App\Models\Incident;
use App\Services\Incident\IncidentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An incident as the dashboard and the public API see it.
 *
 * `connection_ids` are the internal ids for the dashboard; the public API
 * passes `publicIds: true` and gets each connection's public id instead —
 * an auto-increment id says how many connections the platform has.
 *
 * @mixin Incident
 */
class IncidentResource extends JsonResource
{
    public bool $publicIds = false;

    public bool $withStats = true;

    public static function forApi(Incident $incident): self
    {
        $resource = new self($incident);
        $resource->publicIds = true;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        $connections = $this->relationLoaded('connections') ? $this->connections : $this->connections()->get();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'live' => $this->isLive(),
            'match_mode' => $this->match_mode->value,
            'reply_mode' => $this->reply_mode->value,
            'ai_hub_agent_id' => $this->publicIds ? null : $this->ai_hub_agent_id,
            'ai_agent' => $this->publicIds || ! $this->ai_hub_agent_id ? null : (fn ($agent) => $agent ? ['id' => $agent->id, 'name' => $agent->name] : null)($this->aiAgent),
            'ai_brief' => $this->ai_brief,
            'keywords' => $this->keywordList(),
            'exclude_keywords' => $this->excludeKeywordList(),
            'message' => $this->message,
            'media_url' => $this->media_url,
            'media_type' => $this->media_type,
            'reply_in_agent_threads' => $this->reply_in_agent_threads,
            'continue_flow' => $this->continue_flow,
            'notify_recent_minutes' => $this->notify_recent_minutes,
            'resolution_message' => $this->resolution_message,
            'resolution_audience' => $this->resolution_audience->value,
            'connection_ids' => $this->publicIds
                ? $connections->pluck('public_id')->values()
                : $connections->pluck('id')->values(),
            'connections' => $this->publicIds ? null : $connections->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'channel' => $c->channel->value,
            ])->values(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'source' => $this->source->value,
            'created_by' => $this->publicIds ? null : $this->whenLoaded('creator', fn () => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null),
            'resolved_by' => $this->publicIds ? null : $this->whenLoaded('resolver', fn () => $this->resolver ? ['id' => $this->resolver->id, 'name' => $this->resolver->name] : null),
            'stats' => $this->withStats ? app(IncidentService::class)->stats($this->resource) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
