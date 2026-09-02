<?php

namespace App\Exceptions\Billing;

/**
 * The workspace asked to buy something priced up front and the balance will not
 * cover it.
 *
 * Deliberately not the same exception as CreditExhaustedException, even though
 * both mean "no money". That one is discovered by a bot mid-conversation and
 * the only sane response is to hand the customer to a human; this one is
 * discovered by a person who clicked Buy, and the response is a field error
 * telling them how much they are short. Sharing one exception would force every
 * catch site to re-derive which situation it was in.
 *
 * Carries both numbers because "insufficient balance" alone sends the customer
 * to the top-up screen without telling them the amount to type.
 */
class InsufficientCreditException extends \RuntimeException
{
    public function __construct(
        public readonly int $balanceCents,
        public readonly int $requiredCents,
        public readonly string $currency = 'BRL',
    ) {
        parent::__construct(
            "Insufficient credit: balance {$balanceCents}, required {$requiredCents} cents {$currency}."
        );
    }

    /** How much has to be added before the purchase can go through. */
    public function shortfallCents(): int
    {
        return max(0, $this->requiredCents - $this->balanceCents);
    }
}
