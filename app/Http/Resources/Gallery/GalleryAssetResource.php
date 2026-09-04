<?php

namespace App\Http\Resources\Gallery;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One library file as its workspace sees it.
 *
 * `url` is the permanent signed link — the same string the browser renders and
 * the channel fetches. It is in the payload rather than derived on the client
 * because the signature is ours to make: a URL the frontend assembled would be
 * a URL the frontend could get wrong, silently, for every file at once.
 *
 * `path` and `checksum` never appear: they are hidden on the model as well, so
 * a future `->toArray()` somewhere else cannot publish them by accident.
 */
class GalleryAssetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type->value,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'url' => $this->publicUrl(),
            'filename' => $this->public_filename,
            // Which send endpoint this file goes out through, decided by the
            // same enum the backend validates against — so the composer never
            // has to re-derive it from a MIME string and disagree.
            'send_path' => $this->type->sendPath(),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader?->id,
                'name' => $this->uploader?->name,
            ]),
            'last_used_at' => $this->last_used_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
