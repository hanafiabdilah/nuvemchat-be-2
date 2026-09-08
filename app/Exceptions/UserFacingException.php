<?php

namespace App\Exceptions;

use App\Support\Errors\HasUserSafeMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A failure whose wording is ours and should reach the customer untouched.
 *
 * For the cases where we have already done the work of translating an
 * upstream's refusal into an instruction — the WhatsApp migration hints are
 * the archetype: Meta answers `133005`, and we answer "ask your current
 * provider to turn off two-step verification, then run the migration again".
 * Thrown as a plain Exception, that sentence is indistinguishable from the raw
 * text the catch blocks are there to suppress, and gets suppressed with it.
 *
 * ⚠️ It carries its own render(), and that is not decoration — it is the whole
 * reason the sentence survives. Without it, throwing one out of a controller is
 * an unhandled exception: Laravel answers 500 "Server Error", the careful
 * wording never leaves the process, and `httpStatus()` below is read by nobody.
 * That is exactly what happened to the missing-CPF case — a customer who needed
 * to be told to fill in one field got a server error instead, and the only
 * trace was a log line.
 */
class UserFacingException extends RuntimeException implements HasUserSafeMessage
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 422,
        /**
         * A stable code for the UI to branch on, where a toast is not the right
         * answer. `billing_identity_required` is the archetype: the remedy is a
         * form, and a page that can recognise the code can open it instead of
         * printing a sentence and leaving the customer to find it.
         */
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function toResponse(array $extra = []): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            ...$extra,
        ], fn ($value) => $value !== null), $this->httpStatus);
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson() ? $this->toResponse() : null;
    }

    /**
     * Nothing to report: this is a message we chose to send, not a fault. The
     * log would fill with "the customer has not entered their CPF yet".
     */
    public function report(): bool
    {
        return false;
    }
}
