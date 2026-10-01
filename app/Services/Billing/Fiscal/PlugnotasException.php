<?php

namespace App\Services\Billing\Fiscal;

use RuntimeException;

/**
 * Plugnotas refused a request, or could not be reached (status 0).
 *
 * The message is Plugnotas' own sentence. It is only ever shown to platform
 * operators (Back Office) and logged — a tenant reads a nota's status, never
 * this — so it is kept verbatim: it is the one thing that tells an operator
 * which field of the configuration is wrong.
 */
class PlugnotasException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly mixed $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** The request never got an answer: it may or may not have arrived. */
    public function isTransport(): bool
    {
        return $this->status === 0 || $this->status >= 500 || $this->status === 429;
    }

    /** Plugnotas already holds a nota under this idIntegracao. */
    public function isDuplicate(): bool
    {
        return $this->status === 409;
    }
}
