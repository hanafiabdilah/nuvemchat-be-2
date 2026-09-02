<?php

namespace App\Exceptions\Billing;

/**
 * The workspace is running on a rented platform key and has no prepaid credit
 * left to spend on it.
 *
 * Thrown before the hub is called, so an empty wallet costs the platform
 * nothing. Deliberately a sibling of AiRunQuotaExceededException rather than a
 * variant of it: both stop a run for an account reason, but they are fixed in
 * different places — one by upgrading the plan, one by topping up — and a
 * customer told the wrong one goes looking in the wrong screen.
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
