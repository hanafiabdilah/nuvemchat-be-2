<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorage;
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
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => UploadPolicy::rules(10240), // Max 10MB
        ], [
            'file.mimes' => UploadPolicy::message(),
        ]);

        $file = $request->file('file');
        $path = $file->store('uploads', MediaStorage::publishedDiskName());

        return response()->json([
            'url' => MediaStorage::publishedUrl($path),
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }
}
