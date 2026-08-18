<?php

namespace App\Exceptions\Billing;

/**
 * The tenant has used every AI run its plan includes for this billing period.
 *
 * Thrown before the hub is called, so an over-quota workspace costs nothing.
 * Callers decide the consequence: a flow hands the conversation to a human
 * (never leaves the customer talking to silence), a draft suggestion returns
 * 403 and the agent writes the reply themselves.
 */
class AiRunQuotaExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct("AI run quota exceeded ({$used}/{$limit} for the current billing period).");
    }
}
