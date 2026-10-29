<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\TikTokApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Connection\TikTok\TikTokConfig;
use App\Services\TikTok\TikTokUrlProperty;

/**
 * The one platform-side step TikTok publishing needs: proving to TikTok that
 * the address media is published from is ours. See TikTokUrlProperty.
 *
 * Answered in TikTok's own words on failure — operators are the people who fix
 * this, and the vendor's sentence is what tells them which part is wrong.
 */
class AdminTikTokController extends Controller
{
    public function __construct(private readonly TikTokUrlProperty $property) {}

    public function urlProperty()
    {
        return response()->json(['data' => $this->property->status()]);
    }

    public function verifyUrlProperty()
    {
        if (! TikTokConfig::appId() || ! TikTokConfig::appSecret()) {
            return response()->json(['message' => 'Fill in the TikTok App ID and App secret first.'], 422);
        }

        try {
            $status = $this->property->verify();
        } catch (TikTokApiException $e) {
            return response()->json([
                'message' => 'TikTok refused: '.$e->getMessage().($e->tiktokCode() ? " (code {$e->tiktokCode()})" : ''),
            ], 422);
        }

        AuditLog::record('tiktok.url_property_verified', 'Verified the TikTok media URL prefix', [
            'url' => $status['prefix'],
            'verified' => $status['verified'],
        ]);

        return response()->json(['data' => $status]);
    }
}
