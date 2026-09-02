<?php

namespace App\Enums\Credit;

/**
 * What moved a wallet's balance.
 *
 * `Adjustment` is the only one a human can create, and it is signed either way:
 * comping credit after an outage and clawing back a debit that should never
 * have been written are the same operation with a different sign, and both need
 * to be visible in the ledger rather than performed by editing a balance.
 *
 * The spending cases are split by what was bought rather than lumped under one
 * "debit", because they are not read the same way. `Usage` is a stream of tiny
 * amounts nobody inspects individually; `Purchase` and `Renewal` are single
 * amounts a customer will come back and ask about by name. A statement that
 * called all three the same thing would answer "where did my balance go" with a
 * list the customer still has to decode.
 */
enum CreditTransactionType: string
{
    /** A paid top-up invoice. Always positive. */
    case Topup = 'topup';
    /** One AI run charged against the balance. Always negative. */
    case Usage = 'usage';
    /** Buying an asset outright — an API Way instance, a trained agent. Negative. */
    case Purchase = 'purchase';
    /** A cycle renewal of something already owned, so far only API Way. Negative. */
    case Renewal = 'renewal';
    /**
     * Money given back because we could not deliver what it bought. Positive.
     *
     * Deliberately not folded into `Refund`, which moves the other way: that one
     * is a top-up the customer's bank took back, this one is a purchase the
     * platform failed. Same word in Portuguese, opposite direction in the
     * ledger — and a statement where "estorno" can mean either is one nobody can
     * reconcile.
     */
    case Reversal = 'reversal';
    /** A top-up that MercadoPago later refunded or charged back. Negative. */
    case Refund = 'refund';
    /** A Back Office correction, either direction. */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Topup => 'Recarga',
            self::Usage => 'Uso de IA',
            self::Purchase => 'Compra',
            self::Renewal => 'Renovação',
            self::Reversal => 'Devolução',
            self::Refund => 'Estorno',
            self::Adjustment => 'Ajuste manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
