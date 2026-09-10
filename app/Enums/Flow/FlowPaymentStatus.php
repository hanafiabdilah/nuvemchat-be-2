<?php

namespace App\Enums\Flow;

/**
 * Where a charge a flow issued stands.
 *
 * Four states and only one of them is open. `expired` and `failed` both send
 * the flow down the payment node's `failed` output, but they are kept apart
 * because they mean different things to whoever reads the list afterwards:
 * nobody paid in time, versus the charge could not even be created.
 */
enum FlowPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
