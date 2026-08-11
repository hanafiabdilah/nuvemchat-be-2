<?php

namespace App\Exceptions;

use Exception;

/**
 * The provider accepted the request but has not built its chat index yet.
 *
 * Distinct from an unparseable payload: this is a "come back in a minute",
 * which the history import answers by re-queueing itself instead of giving up.
 */
class ChatListNotReadyException extends Exception
{
}
