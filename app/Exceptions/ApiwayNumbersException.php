<?php

namespace App\Exceptions;

use Exception;

/**
 * Normalized failure from the API Way numbers portal.
 *
 * The portal answers with plain HTTP statuses and a `message`, so the error
 * code is ours: the status alone cannot separate "this DDD does not exist"
 * (the tenant picked badly) from "the account is at its cap" (nothing the
 * tenant did, and nothing they can fix).
 */
class ApiwayNumbersException extends Exception
{
    /**
     * The account has as many live numbers as API Way allows.
     *
     * Arrives as a 422 with `cap.used` / `cap.max`, but unlike every other 422
     * here nothing about the request is wrong — the platform's own ceiling is
     * full, and raising it is a conversation with API Way, not a fix in this
     * code. Surfaced to the tenant as "out of stock" and to us as a log line
     * worth acting on.
     */
    public const CAP_REACHED = 'cap_reached';

    /** API Way has number sales switched off for this account (403). */
    public const SALES_DISABLED = 'sales_disabled';

    /** Contracting/cancelling is momentarily unavailable upstream (502). */
    public const UPSTREAM_UNAVAILABLE = 'upstream_unavailable';

    public const NOT_FOUND = 'not_found';

    public const UNAUTHENTICATED = 'unauthenticated';

    /**
     * The purchase failed and the charge has already been returned.
     *
     * Its own code because it is the one failure the customer needs told
     * differently: they are looking at a balance that was debited a second ago,
     * and "unavailable" alone leaves them to wonder where the money went.
     */
    public const PURCHASE_REVERSED = 'purchase_reversed';

    public const UNCONFIGURED = 'unconfigured';

    public const INVALID_REQUEST = 'invalid_request';

    /**
     * @param  array{used?: int, max?: int}|null  $cap  Populated on CAP_REACHED.
     */
    public function __construct(
        string $message,
        protected readonly string $errorCode = 'apiway_numbers_error',
        protected readonly int $httpStatus = 500,
        protected readonly ?array $cap = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array{used?: int, max?: int}|null */
    public function getCap(): ?array
    {
        return $this->cap;
    }

    public function isCapReached(): bool
    {
        return $this->errorCode === self::CAP_REACHED;
    }

    /**
     * Whether trying again later could work.
     *
     * ⚠️ Read by callers deciding whether to refund a charge, never by an
     * automatic retry of `POST /numbers`: that endpoint takes no idempotency
     * key, so a blind retry buys — and bills us for — a second number.
     */
    public function isRetriable(): bool
    {
        return $this->httpStatus >= 500 || $this->httpStatus === 429 || $this->httpStatus === 0;
    }
}
