<?php

namespace App\Services\MetaAds;

use RuntimeException;

/**
 * Meta refused a call about an ad account.
 *
 * `needsLogin` separates the two things an operator can do about it: a token
 * Meta no longer accepts (expired, revoked, permission removed) only a new
 * login fixes; anything else — a rate limit, an outage — the next sync may.
 */
class MetaAdsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $needsLogin = false,
        public readonly ?int $metaCode = null,
        /** Meta's words rather than ours: logged, never shown to the customer. */
        public readonly bool $fromMeta = false,
    ) {
        parent::__construct($message);
    }
}
