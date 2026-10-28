<?php

namespace App\Exceptions;

use Exception;

/**
 * A refusal from Google — the YouTube Data API or the OAuth token endpoint.
 *
 * Carries Google's `reason` (quotaExceeded, commentsDisabled, invalid_grant…)
 * because that, not the prose, is what decides what the customer is told.
 * The prose itself is written for developers and is translated on the way out
 * (bootstrap/app.php → UpstreamError), never shown.
 */
class YoutubeApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly ?string $reason = null,
        private readonly int $httpStatus = 502,
    ) {
        parent::__construct($message);
    }

    /**
     * Read Google's error envelope.
     *
     * The Data API answers `{error: {code, message, errors: [{reason}]}}`; the
     * OAuth endpoint answers `{error: "invalid_grant", error_description}`.
     * Both reach here, so both shapes are read.
     */
    public static function fromResponse(array $body, int $status): self
    {
        $error = $body['error'] ?? null;

        if (is_string($error)) {
            return new self($body['error_description'] ?? $error, $error, self::statusFor($status));
        }

        $error = is_array($error) ? $error : [];
        $reason = $error['errors'][0]['reason'] ?? $error['status'] ?? null;

        return new self(
            $error['message'] ?? 'YouTube rejected the request.',
            $reason !== null ? (string) $reason : null,
            self::statusFor($status),
        );
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Whether only connecting the channel again can fix this.
     *
     * A refused refresh token (revoked in the Google account, a password
     * change, a test-mode app's seven-day expiry) and a token without the
     * scope both land here — the one failure the page can offer a remedy for.
     */
    public function needsReconnect(): bool
    {
        return in_array($this->reason, [
            'invalid_grant',
            'reconnect_required',
            'authError',
            'insufficientPermissions',
            'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
            'UNAUTHENTICATED',
        ], true);
    }

    private static function statusFor(int $status): int
    {
        // 4xx is "fix the request", passed on as 422 so the SPA does not read
        // a Google 401 as its own session ending; the rest is theirs.
        return $status >= 400 && $status < 500 ? 422 : 502;
    }
}
