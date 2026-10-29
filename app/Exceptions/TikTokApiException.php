<?php

namespace App\Exceptions;

use Exception;

/**
 * A refusal from TikTok's Accounts API.
 *
 * TikTok answers `{code, message, data}` with HTTP 200 even when it refuses,
 * so `code` — not the status — is what is carried. The message is written for
 * developers and is translated on the way out (bootstrap/app.php →
 * UpstreamError), never shown.
 */
class TikTokApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly ?string $tiktokCode = null,
        private readonly int $httpStatus = 502,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(array $body, int $status): self
    {
        $code = isset($body['code']) ? (string) $body['code'] : null;

        return new self(
            (string) ($body['message'] ?? 'TikTok rejected the request.'),
            $code,
            // TikTok's 5xxxx are its own trouble; everything else is about the
            // request, passed on as 422 so the SPA does not read it as its own
            // session ending.
            $code !== null && str_starts_with($code, '5') ? 502 : ($status >= 500 ? 502 : 422),
        );
    }

    public function tiktokCode(): ?string
    {
        return $this->tiktokCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Only connecting the account again can fix this: the token lacks the
     * Accounts API permissions (the account was connected for the inbox before
     * posting existed), or the grant is gone.
     */
    public function needsReconnect(): bool
    {
        return in_array($this->tiktokCode, ['40001', '40102', '40104', '40105', '40107', '40131', 'reconnect_required'], true);
    }

    public function isRateLimited(): bool
    {
        return in_array($this->tiktokCode, ['40100', '40133'], true);
    }
}
