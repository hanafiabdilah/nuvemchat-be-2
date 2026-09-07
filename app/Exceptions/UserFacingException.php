<?php

namespace App\Exceptions;

use App\Support\Errors\HasUserSafeMessage;
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
 */
class UserFacingException extends RuntimeException implements HasUserSafeMessage
{
    public function __construct(string $message, private readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
