<?php

namespace App\Services\Push;

/**
 * What FCM said about one send. Three outcomes the caller acts on differently:
 * sent; the token is dead (delete the row); worth retrying (5xx, 429).
 * Anything else is a failure that a retry would only repeat.
 */
final class PushResult
{
    public const SENT = 'sent';

    public const INVALID_TOKEN = 'invalid_token';

    public const RETRYABLE = 'retryable';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $outcome,
        public readonly ?string $error = null,
        public readonly ?string $messageId = null,
    ) {}

    public function sent(): bool
    {
        return $this->outcome === self::SENT;
    }
}
