<?php

namespace App\Http\Controllers;

use App\Models\GalleryAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a gallery file to whoever holds its signed URL.
 *
 * Unauthenticated by necessity, not by oversight: the only readers that matter
 * are Meta, Telegram and Discord fetching the bytes to deliver them, and none
 * of them carries a session. The signature is the credential — the same
 * arrangement message media already runs on, and the reason `uuid` rather than
 * an id is in the URL.
 *
 * No expiry, unlike message media. A gallery asset lives until its tenant
 * deletes it, and every bubble ever sent with it points here; a link that timed
 * out on a schedule would turn a year of history into broken images to protect
 * a file the customer is deliberately publishing to their own customers.
 */
class GalleryFileController extends Controller
{
    /**
     * The `filename` segment is signed but otherwise ignored: it is there so
     * the URL ends in a real extension, which is the only thing telling
     * OutboundMedia what MIME type to send and WhatsApp what to call the
     * document. Looking the asset up by it as well would make a rename able to
     * break every message that used the file.
     */
    public function show(Request $request, string $uuid, string $filename): StreamedResponse
    {
        $asset = GalleryAsset::where('uuid', $uuid)->firstOrFail();

        $disk = Storage::disk((string) config('gallery.disk', 'local'));

        abort_unless($disk->exists($asset->path), 404);

        return $disk->response($asset->path, $asset->public_filename, [
            'Content-Type' => $asset->mime_type,
            // Long and immutable: the bytes behind a uuid never change (an
            // edit is a new asset), so every fetcher and every browser that
            // has seen it once should never ask again.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
