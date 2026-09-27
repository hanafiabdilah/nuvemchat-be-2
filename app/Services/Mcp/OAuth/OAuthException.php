<?php

namespace App\Services\Mcp\OAuth;

use RuntimeException;

/**
 * An OAuth error, in the shape RFC 6749 §5.2 defines.
 *
 * The `error` code is the part clients branch on — `invalid_grant` tells a
 * client to start a new authorization, `invalid_request` tells it to fix its
 * call — so it is the part that matters and it comes from a fixed vocabulary.
 * The description is for whoever is reading a terminal.
 */
class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description);
    }

    public static function invalidClient(string $description): self
    {
        return new self('invalid_client', $description, 401);
    }

    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description);
    }

    public static function unsupportedGrantType(string $description): self
    {
        return new self('unsupported_grant_type', $description);
    }

    /** @return array{error: string, error_description: string} */
    public function payload(): array
    {
        return [
            'error' => $this->error,
            'error_description' => $this->getMessage(),
        ];
    }
}
