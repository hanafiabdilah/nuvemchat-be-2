<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * Media for flows, carousel cards and campaigns. The returned URL is written
     * into those and sent again for months, so it lives on the published disk,
     * whose addresses never expire.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // Max 10MB
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
