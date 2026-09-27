<?php

namespace App\Services\Contact\Photo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The time budget for one contact-photo sync, in one place.
 *
 * ⚠️ This exists because the numbers drifted apart and took the queue down with
 * them. Every lookup used Http::timeout(20) and the download used 30, while
 * SyncContactPhoto declared $timeout = 60 — and Telegram needs up to three
 * lookups (getChat / getUserProfilePhotos / getFile), so its worst case was
 * 3 × 20 + 30 = 90s against a 60s job timeout.
 *
 * A job that hits its timeout does not just fail: Laravel's worker cannot
 * safely continue past an alarm, so the whole `queue:work` process exits. On 26
 * Sep 2026 that happened twice, and each time everything queued behind it —
 * including a flow's 3-second pause — waited about 61 seconds for the container
 * to come back. The flow looked broken; the photo lookup was the one at fault.
 *
 * So the budget is declared here, the job's timeout is derived from it, and a
 * test asserts the derivation. Raising a timeout in a resolver without coming
 * through this file is what caused the outage; it is now the only way to.
 */
final class PhotoHttp
{
    /** One channel API call: "does this contact have a picture, and where?" */
    public const LOOKUP_TIMEOUT = 8;

    /** Fetching the image itself. Profile pictures are small. */
    public const DOWNLOAD_TIMEOUT = 15;

    /**
     * Most lookups one resolver makes before it has a URL.
     *
     * Three, because of Telegram: a group needs getChat, a user needs
     * getUserProfilePhotos, and either way getFile turns the id into a path.
     */
    public const MAX_LOOKUPS = 3;

    /**
     * Longest a sync can legitimately take over the network, which is what the
     * job's own timeout has to stay clear of.
     */
    public const BUDGET = self::MAX_LOOKUPS * self::LOOKUP_TIMEOUT + self::DOWNLOAD_TIMEOUT;

    /**
     * Headroom between the network budget and the job timeout, for the work that
     * is not an HTTP call: hashing the image and writing it to object storage,
     * which is a round trip to another region.
     */
    public const OVERHEAD = 25;

    public static function lookup(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(self::LOOKUP_TIMEOUT);
    }

    public static function download(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(self::DOWNLOAD_TIMEOUT);
    }
}
