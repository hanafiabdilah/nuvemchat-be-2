<?php

namespace App\Services\Gallery;

use App\Enums\Gallery\AssetType;
use App\Models\GalleryAsset;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Turns `gallery_asset_id` on a send request into the `media_url` the channel
 * handlers already understand.
 *
 * The gallery has no sender of its own, and should not: every channel already
 * knows how to send media by URL, and a parallel path would be nine handlers to
 * keep in step for no new capability. Picking a file resolves to the same
 * `send-image` / `send-video` / `send-audio` / `send-document` route the
 * composer has always called.
 *
 * Resolving it **on the server** rather than letting the client post the URL is
 * the point of the parameter. `media_url` is a free-form URL, so a client that
 * built it itself could aim an outbound send at anything reachable from this
 * machine, and there would be no moment at which the platform knew a library
 * file had been used. An id is a claim about something the workspace owns, and
 * the ownership is checked here.
 */
class GalleryMediaResolver
{
    public function __construct(
        private readonly GalleryService $gallery,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  AssetType  $expected  the kind of media this endpoint sends
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function apply(array $data, ?Tenant $tenant, AssetType $expected): array
    {
        $id = $data['gallery_asset_id'] ?? null;

        if ($id === null || $id === '' || $tenant === null) {
            return $data;
        }

        $asset = GalleryAsset::forTenant($tenant->id)->find($id);

        if ($asset === null) {
            throw ValidationException::withMessages([
                'gallery_asset_id' => ['Este arquivo não está na galeria deste workspace.'],
            ]);
        }

        if ($asset->type !== $expected) {
            // Reachable when a picker sends a document down the image route, so
            // it names both kinds: the channel's own error for the same mistake
            // arrives as an unexplained upload failure minutes later.
            throw ValidationException::withMessages([
                'gallery_asset_id' => [
                    "Este arquivo é do tipo {$asset->type->value} e não pode ser enviado como {$expected->value}.",
                ],
            ]);
        }

        unset($data['gallery_asset_id']);
        $data['media_url'] = $asset->publicUrl();

        // Best-effort and deliberately before the send: this is a usage stamp
        // for a list ordering, and a failed send is still the moment somebody
        // reached for the file.
        $this->gallery->markUsed($asset);

        return $data;
    }
}
