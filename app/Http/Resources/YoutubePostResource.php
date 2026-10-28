<?php

namespace App\Http\Resources;

use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A video record of ours — a draft, an upload on its way, a failure, or the
 * receipt for one on YouTube. Never carries the upload session URI: it
 * authorises writes to the upload.
 */
class YoutubePostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $size = (int) $this->video_size;

        return [
            'id' => $this->id,
            'youtube_account_id' => (string) $this->youtube_account_id,
            'status' => $this->status->value,
            'title' => $this->title,
            'description' => $this->description,
            'tags' => $this->tags ?? [],
            'category_id' => $this->category_id,
            'privacy_status' => $this->privacy_status,
            'made_for_kids' => (bool) $this->made_for_kids,
            'notify_subscribers' => (bool) $this->notify_subscribers,
            'video' => $this->video_path ? [
                'path' => $this->video_path,
                'name' => $this->video_name,
                'size' => $size,
                'mime' => $this->video_mime,
                'preview_url' => MediaStorage::signedUrl($this->video_path, now()->addDay()),
            ] : null,
            // How far the bytes got, for the tile's progress bar.
            'progress' => $size > 0 ? min(100, (int) floor(((int) $this->uploaded_bytes / $size) * 100)) : 0,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'yt_video_id' => $this->yt_video_id,
            'url' => $this->watchUrl(),
            'error' => $this->error,
            'created_by' => UserResource::make($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
