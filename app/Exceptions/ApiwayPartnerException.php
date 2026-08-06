<?php

namespace App\Exceptions;

use Exception;

/**
 * Normalized failure from the ProxyBR partner API. Carries the `error` code
 * from the partner envelope ({error, message}) plus the HTTP status, so
 * callers can decide between retry, fail-fast and user-facing messaging.
 */
class ApiwayPartnerException extends Exception
{
    /** Partner error codes that must never be retried automatically. */
    private const NON_RETRIABLE = [
        'no_enabled_subnet_capacity',
        'invalid_body',
        'invalid_quantity',
        'invalid_cycle',
        'location_not_available',
        'invalid_state',
        'not_found',
        'partner_api_disabled',
        'internal_token_platform_required',
        'forbidden',
        'confirmation_required',
    ];

    public function __construct(
        string $message,
        protected readonly ?string $errorCode = null,
        protected readonly int $httpStatus = 500,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Whether a retry (queue backoff) has any chance of succeeding.
     * Transport failures and 5xx/502 apiway_* hub errors are retriable;
     * validation/capacity/state errors are not.
     */
    public function isRetriable(): bool
    {
        if (in_array($this->errorCode, self::NON_RETRIABLE, true)) {
            return false;
        }

        return $this->httpStatus >= 500 || $this->httpStatus === 0 || $this->httpStatus === 429;
    }
}
