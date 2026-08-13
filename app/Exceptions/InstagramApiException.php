<?php

namespace App\Exceptions;

use Exception;

/**
 * A refusal from the Instagram Graph API, carrying Meta's own words.
 *
 * Meta's messages are the useful part of a publishing failure ("The submitted
 * image is not a valid JPEG", "Application does not have permission for this
 * action") and are written for developers, so they are surfaced to the user
 * rather than replaced with something vaguer of our own.
 */
class InstagramApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly ?string $metaCode = null,
        private readonly ?int $metaSubcode = null,
        private readonly int $httpStatus = 502,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(array $body, int $status): self
    {
        $error = $body['error'] ?? [];

        return new self(
            $error['error_user_msg'] ?? $error['message'] ?? 'Instagram rejected the request.',
            isset($error['code']) ? (string) $error['code'] : null,
            $error['error_subcode'] ?? null,
            // Meta answers 400 for "your fault" and 5xx for its own; anything
            // in the 4xx range is passed through so the SPA can tell the user
            // to fix the post rather than to try again.
            $status >= 400 && $status < 500 ? 422 : 502,
        );
    }

    public function metaCode(): ?string
    {
        return $this->metaCode;
    }

    public function metaSubcode(): ?int
    {
        return $this->metaSubcode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Whether this is Meta telling us the token lacks a scope.
     *
     * Code 10 / subcode 2534015 and code 200 are the permission family. They
     * mean the tenant connected Instagram before publishing was added and has
     * to re-authorize — a very different message from "your image is invalid",
     * and the only failure the UI can offer a fix for.
     */
    public function isPermissionError(): bool
    {
        return in_array($this->metaCode, ['10', '200', '3'], true)
            || $this->metaSubcode === 2534015;
    }
}
