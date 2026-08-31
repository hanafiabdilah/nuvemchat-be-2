<?php

namespace App\Enums\AiCredit;

/**
 * What moved a wallet's balance.
 *
 * `Adjustment` is the only one a human can create, and it is signed either way:
 * comping credit after an outage and clawing back a debit that should never
 * have been written are the same operation with a different sign, and both need
 * to be visible in the ledger rather than performed by editing a balance.
 */
enum CreditTransactionType: string
{
    /** A paid top-up invoice. Always positive. */
    case Topup = 'topup';
    /** One AI run charged against the balance. Always negative. */
    case Usage = 'usage';
    /** A top-up that MercadoPago later refunded or charged back. Negative. */
    case Refund = 'refund';
    /** A Back Office correction, either direction. */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Topup => 'Recarga',
            self::Usage => 'Uso de IA',
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
