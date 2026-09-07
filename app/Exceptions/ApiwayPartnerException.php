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
    /**
     * Refusals that mean "not right now" rather than "not ever": the request is
     * valid, the money is already collected on our side, and ProxyBR simply
     * will not allocate at this moment. Retrying is the only correct answer —
     * failing hands the customer a manual refund for something an admin raises
     * in one click, and they wanted the instance, not their money back.
     *
     * `no_enabled_subnet_capacity` deliberately stays out: that one is real
     * IPv4 stock exhaustion with no ETA, so holding a paid purchase open
     * indefinitely would be the crueller answer.
     */
    private const CAPACITY_HOLD = [
        'platform_capacity_reached',
    ];

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

    /**
     * @param  string  $message  Ours, safe to show a tenant — see
     *         ApiwayPartnerClient::decode(), which translates before throwing.
     * @param  string|null  $rawMessage  ProxyBR's own sentence. For logs and
     *         for the Back Office, which is where an operator diagnoses this;
     *         never for a tenant.
     */
    public function __construct(
        string $message,
        protected readonly ?string $errorCode = null,
        protected readonly int $httpStatus = 500,
        ?\Throwable $previous = null,
        protected readonly ?string $rawMessage = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * What ProxyBR actually said, falling back to our copy when the partner
     * sent nothing quotable.
     */
    public function getRawMessage(): string
    {
        return $this->rawMessage ?: $this->getMessage();
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * ProxyBR is at a self-imposed ceiling. Arrives as a 422, but unlike every
     * other 422 on this surface nothing about the request is wrong — see
     * CAPACITY_HOLD.
     */
    public function isCapacityHold(): bool
    {
        return in_array($this->errorCode, self::CAPACITY_HOLD, true);
    }

    /**
     * Whether a retry (queue backoff) has any chance of succeeding.
     * Transport failures and 5xx/502 apiway_* hub errors are retriable;
     * validation/state errors are not.
     */
    public function isRetriable(): bool
    {
        if ($this->isCapacityHold()) {
            return true;
        }

        if (in_array($this->errorCode, self::NON_RETRIABLE, true)) {
            return false;
        }

        return $this->httpStatus >= 500 || $this->httpStatus === 0 || $this->httpStatus === 429;
    }
}
