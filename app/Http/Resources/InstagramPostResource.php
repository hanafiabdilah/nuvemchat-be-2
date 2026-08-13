<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A post record of ours — a draft, a schedule, or the receipt for a published
 * one. Shaped to sit next to a live Instagram media object in the same grid,
 * so it carries a `thumbnail_url` and a `caption` under the same names.
 */
class InstagramPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'media_type' => $this->media_type->value,
            'caption' => $this->caption,
            'connection_id' => (string) $this->connection_id,
            'created_by' => UserResource::make($this->whenLoaded('creator')),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'ig_media_id' => $this->ig_media_id,
            'permalink' => $this->permalink,
            'error' => $this->error,
            'items' => $items instanceof \Illuminate\Support\Collection
                ? $items->map(fn ($item) => [
                    'id' => $item->id,
                    'position' => (int) $item->position,
                    'media_type' => $item->media_type,
                    'url' => $item->url,
                ])->values()
                : [],
            // The tile's image. Video has no still of its own until Instagram
            // makes one, so a scheduled reel shows its own file and the grid
            // renders it in a <video> element.
            'thumbnail_url' => $items instanceof \Illuminate\Support\Collection
                ? $items->first()?->url
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
