<?php

namespace App\Http\Resources\Gallery;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One folder, flat: the dashboard receives the whole tree as a list and builds
 * breadcrumbs and paths from `parent_id` itself. A workspace has at most a few
 * hundred folders, and one list call is cheaper than a request per level.
 */
class GalleryFolderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            // Files directly inside, of every origin. Not recursive: the tile
            // says what opening it will show.
            'assets_count' => (int) ($this->assets_count ?? 0),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
