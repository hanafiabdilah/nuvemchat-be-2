<?php

namespace App\Services\Message;

use App\Support\Errors\TransportFailure;
use Illuminate\Http\Client\ConnectionException;

/**
 * Whether a failed button send may be followed by the same message as text.
 *
 * Only when the buttons cannot have reached anyone. The core answering "no"
 * is that; so is never getting a connection. A request that went out and lost
 * its answer is neither: the customer may be looking at the buttons already,
 * and a text copy on top of them is the same question asked twice.
 */
class InteractiveDelivery
{
    public static function canFallBack(\Throwable $th): bool
    {
        for ($e = $th; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ConnectionException) {
                return TransportFailure::undelivered($th);
            }
        }

        return true;
    }
}
