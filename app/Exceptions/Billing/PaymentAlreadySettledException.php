<?php

namespace App\Exceptions\Billing;

/**
 * Thrown when a cancellation loses the race against the payment it was trying to
 * close — the charge settled at the payment service first, so the subscription is now
 * paid for and must not be torn down.
 */
class PaymentAlreadySettledException extends \RuntimeException
{
    public function __construct(string $message = 'Payment was already confirmed.')
    {
        parent::__construct($message);
    }
}
