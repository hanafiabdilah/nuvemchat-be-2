<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A TikTok post record of ours — shaped to sit beside a live post in the grid. */
class TikTokPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items');
        $items = $items instanceof \Illuminate\Support\Collection ? $items : collect();

        return [
            'id' => $this->id,
            'connection_id' => (string) $this->connection_id,
            'status' => $this->status->value,
            'media_type' => $this->media_type,
            'title' => $this->title,
            'caption' => $this->caption,
            'privacy_level' => $this->privacy_level,
            'disable_comment' => (bool) $this->disable_comment,
            'disable_duet' => (bool) $this->disable_duet,
            'disable_stitch' => (bool) $this->disable_stitch,
            'is_ai_generated' => (bool) $this->is_ai_generated,
            'is_brand_organic' => (bool) $this->is_brand_organic,
            'is_branded_content' => (bool) $this->is_branded_content,
            'auto_add_music' => (bool) $this->auto_add_music,
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'position' => (int) $item->position,
                'media_type' => $item->media_type,
                'url' => $item->url,
                'path' => $item->path,
            ])->values(),
            'thumbnail_url' => $items->first()?->url,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'tiktok_item_id' => $this->tiktok_item_id,
            'error' => $this->error,
            'created_by' => UserResource::make($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
