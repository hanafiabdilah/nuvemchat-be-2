<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\Gallery\AssetOrigin;
use App\Enums\Media\UploadConflict;
use App\Exceptions\Media\FileAlreadyExistsException;
use App\Services\Media\PublishedUpload;
use App\Services\Media\StickerImage;
use App\Services\Media\UploadPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    /**
     * Media for flows, carousel cards, campaigns and products. Every file also
     * appears in the workspace's gallery, labelled by `purpose` and free of the
     * storage quota — see GalleryLibrary.
     *
     * The returned URL is written
     * into those and sent again for months, so it lives on the published disk,
     * whose addresses never expire.
     *
     * ⚠️ The type allow-list is not housekeeping. The published disk is served
     * straight off our own domain, and Laravel names the stored file after the
     * extension it guesses from the file's *content* — so an HTML file uploaded
     * as "invoice.png" used to become a live page on the origin that serves the
     * dashboard and the Back Office. See UploadPolicy.
     *
     * ⚠️ The stored name is the customer's name for this file, not ours. This
     * URL is written into a flow node, a carousel card or a campaign and sent
     * for months, and several channels have no filename field — WhatsApp names
     * a document after the last segment of the URL it fetched, and Instagram
     * and Messenger offer no other name at all. `$file->store()` used to write
     * Laravel's `hashName()` here, so a business that attached
     * `Contrato de Serviço.pdf` asked every one of its customers to open
     * `8f3a2b1c9d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a.pdf`. See MediaFilename.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => PublishedUpload::rules(),
            'on_conflict' => ['sometimes', Rule::enum(UploadConflict::class)],
            // What the file is for — only to label its gallery tile. Absent
            // means an older client; the file is still listed, as "other".
            'purpose' => ['sometimes', 'nullable', Rule::in(AssetOrigin::uploadPurposes())],
            // A picture for a sticker bubble: stored as the finished sticker.
            'sticker' => ['sometimes', 'boolean'],
        ], [
            'file.mimes' => UploadPolicy::message(),
        ]);

        $file = $request->file('file');

        if ($request->boolean('sticker')) {
            $file = $this->asSticker($file);
        }

        $onConflict = UploadConflict::tryFrom((string) ($validated['on_conflict'] ?? ''))
            ?? UploadConflict::Cancel;

        try {
            return response()->json(PublishedUpload::store(
                $file,
                $request->user()->tenant,
                $onConflict,
                AssetOrigin::tryFrom((string) ($validated['purpose'] ?? '')) ?? AssetOrigin::Upload,
                $request->user(),
            ));
        } catch (FileAlreadyExistsException $e) {
            // 409, not 422: nothing about the request is wrong. The workspace
            // simply already has this name, and only the person can say whether
            // that means replace it, keep both, or stop.
            return response()->json($e->payload(), 409);
        }
    }

    /**
     * The upload made into the sticker it will be sent as (see StickerImage),
     * so the file a flow keeps sending for months is already the WebP the
     * channel wants — sent by URL, never converted again — and the name it is
     * stored under ends in what it is.
     */
    private function asSticker(UploadedFile $file): UploadedFile
    {
        $bytes = (string) @file_get_contents($file->getRealPath());
        $webp = $bytes === '' ? null : StickerImage::toWebp($bytes);

        if ($webp === null) {
            throw ValidationException::withMessages([
                'file' => 'Este arquivo não pode ser enviado como figurinha. Use uma imagem PNG, JPG ou WebP.',
            ]);
        }

        if ($webp === $bytes && StickerImage::isWebp($bytes)) {
            return $file;
        }

        $path = tempnam(sys_get_temp_dir(), 'sticker_');
        file_put_contents($path, $webp);

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'sticker';

        return new UploadedFile($path, $name.'.webp', 'image/webp', null, true);
    }
}
