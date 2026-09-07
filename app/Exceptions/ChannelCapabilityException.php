<?php

namespace App\Exceptions;

use App\Support\Errors\HasUserSafeMessage;
use RuntimeException;

/**
 * "This channel cannot do that" — a rule of the platform, not a failure.
 *
 * Its own class so the send chokepoint (MessageService) can tell it apart from
 * everything else a handler throws. Everything else is a refusal from outside
 * and gets translated into our own vaguer words; this one is already ours,
 * already specific, and is the most useful sentence the agent can be given:
 * "Instagram não permite editar mensagens" ends the attempt, where "não foi
 * possível enviar" invites a second try that will fail identically.
 *
 * 422, never 502: nothing is broken and retrying changes nothing.
 */
class ChannelCapabilityException extends RuntimeException implements HasUserSafeMessage
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
