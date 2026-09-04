<?php

namespace App\Exceptions\Gallery;

/**
 * The upload would put the library over the space the workspace has.
 *
 * Carries all three numbers for the same reason InsufficientCreditException
 * carries two: the screen that made this call has to say how much space is
 * missing, and a bare "out of space" leaves the customer to work out whether
 * they should delete something or rent a gigabyte — and how much of either.
 */
class GalleryQuotaExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $usedBytes,
        public readonly int $limitBytes,
        public readonly int $requestedBytes,
    ) {
        parent::__construct(
            "Gallery quota exceeded: {$usedBytes} of {$limitBytes} bytes used, {$requestedBytes} more requested."
        );
    }

    /** Bytes that have to be freed (or rented) before this file fits. */
    public function shortfallBytes(): int
    {
        return max(0, ($this->usedBytes + $this->requestedBytes) - $this->limitBytes);
    }
}
