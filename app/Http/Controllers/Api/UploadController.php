<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Media\PublishedUpload;
use App\Services\Media\UploadPolicy;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * Media for flows, carousel cards and campaigns. The returned URL is written
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
        $request->validate([
            'file' => PublishedUpload::rules(),
        ], [
            'file.mimes' => UploadPolicy::message(),
        ]);

        return response()->json(PublishedUpload::store($request->file('file')));
    }
}
