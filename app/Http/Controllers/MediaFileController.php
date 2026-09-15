<?php

namespace App\Http\Controllers;

use App\Services\Media\MediaStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\Response;

/**
 * A private media file, to whoever holds its signed link.
 *
 * Message media, avatars, contact photos and widget uploads are all handed out
 * as /storage/{path}?expires=…&signature=… (MediaStorage::signedUrl). This
 * route replaces Laravel's built-in `storage.local` one at the same address and
 * with the same relative signature, so every link already cached in a
 * dashboard's IndexedDB keeps working when the bytes move.
 *
 * Where the bytes are decides the answer:
 *
 * - local disk: streamed from here, exactly as before;
 * - object storage: a redirect to a presigned URL. Our link lives as long as
 *   the file (30 days to 7 months), a presigned one cannot outlive 7 days —
 *   so ours is the long-lived half and the bucket's the short one, and the
 *   bytes never pass through this server.
 *
 * While media is being moved (MEDIA_LEGACY_DISK), a file that is not in the
 * bucket yet is still served from the old disk.
 */
class MediaFileController extends Controller
{
    public function show(Request $request, string $path): Response
    {
        // Same answer Laravel's own route gave: no hint in production that a
        // path exists behind a bad or expired signature.
        abort_unless($request->hasValidRelativeSignature(), app()->isProduction() ? 404 : 403);

        try {
            if (MediaStorage::isLocalDisk(MediaStorage::diskName())) {
                return $this->stream(MediaStorage::disk(), $request, $path);
            }

            $legacy = MediaStorage::legacyDisk();

            if ($legacy !== null && ! MediaStorage::disk()->exists($path) && $legacy->exists($path)) {
                return $this->stream($legacy, $request, $path);
            }

            // Cached for less time than the presigned URL lives (at least an
            // hour, see presignedUrl), so a cached redirect never points at a
            // dead address.
            return redirect()->away(MediaStorage::presignedUrl($path), 302, [
                'Cache-Control' => 'private, max-age=600',
            ]);
        } catch (PathTraversalDetected) {
            abort(404);
        }
    }

    private function stream(FilesystemAdapter $disk, Request $request, string $path): Response
    {
        abort_unless($disk->exists($path), 404);

        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ];

        $response = $disk->serve($request, $path, headers: $headers);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->replace($headers);
        }

        return $response;
    }
}
