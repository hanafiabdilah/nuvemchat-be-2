<?php

namespace App\Exceptions;

use App\Support\Errors\HasUserSafeMessage;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A failure that came from outside, already translated into our own words.
 *
 * Built only by App\Support\Errors\UpstreamError, which is what makes the
 * guarantee hold: getMessage() is copy we wrote, and the upstream's own
 * sentence lives in $rawMessage — read by logs and by the Back Office, never
 * rendered for a tenant.
 *
 * The exception carries its own render(), so any call site can simply throw and
 * the customer still gets the safe answer with the right status.
 */
class UpstreamServiceException extends RuntimeException implements HasUserSafeMessage
{
    /**
     * ⚠️ `errorCode`, not `code`: Exception already owns a non-readonly `$code`
     * and redeclaring it is a fatal error, not a warning.
     */
    public function __construct(
        public readonly UpstreamProvider $provider,
        string $userMessage,
        public readonly string $errorCode,
        public readonly int $httpStatus = 502,
        public readonly string $reference = '',
        public readonly ?string $rawMessage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @param  array<string, mixed>  $extra  Payload the caller needs alongside
     *         the message (a balance, a cap). Never prose.
     */
    public function toResponse(array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            // Quoted back by a customer in a support conversation, and the only
            // way to find the log line holding the upstream's actual words.
            'ref' => $this->reference,
        ], $extra), $this->httpStatus);
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson() ? $this->toResponse() : null;
    }

    /**
     * UpstreamError already wrote the full line, upstream message included.
     * Reporting again would duplicate it and bury the reference.
     */
    public function report(): bool
    {
        return false;
    }
}
