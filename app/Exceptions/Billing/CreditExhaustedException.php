<?php

namespace App\Exceptions\Billing;

/**
 * The workspace is running on a rented platform key and has no prepaid credit
 * left to spend on it.
 *
 * Thrown before the hub is called, so an empty wallet costs the platform
 * nothing. Only rented keys are gated: a workspace on its own provider key
 * pays the provider directly, and the plan puts no cap on how many runs it
 * makes.
 *
 * Callers decide the consequence. A flow hands the conversation to a human
 * (never leaves the customer talking to silence); a draft suggestion returns
 * 402 and the agent writes the reply themselves.
 */
class CreditExhaustedException extends \RuntimeException
{
    public function __construct(
        public readonly int $balanceCents,
        public readonly string $currency = 'BRL',
    ) {
        parent::__construct("Credit balance exhausted ({$balanceCents} cents {$currency}).");
    }
}
