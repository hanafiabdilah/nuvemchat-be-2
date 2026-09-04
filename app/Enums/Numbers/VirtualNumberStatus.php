<?php

namespace App\Enums\Numbers;

/**
 * Where a rented number stands.
 *
 * Four states, and only two of them are API Way's. `Pending` and `Failed` are
 * ours, and they exist because the money moves here before the number exists
 * there: a row has to be able to say "charged, not delivered yet" and "charged,
 * refunded, never delivered".
 */
enum VirtualNumberStatus: string
{
    /**
     * Paid for, but API Way has not confirmed the number.
     *
     * Normally invisible — the purchase call answers in seconds. A row that
     * stays here is the ambiguous case: the request timed out, so the number
     * may or may not exist upstream, and `numbers:sync` is what resolves it.
     */
    case Pending = 'pending';

    case Active = 'active';

    /** Ended: by the tenant, by us when the balance ran out, or upstream. */
    case Cancelled = 'cancelled';

    /** Never delivered. The charge has been returned to the balance. */
    case Failed = 'failed';

    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ativando',
            self::Active => 'Ativo',
            self::Cancelled => 'Cancelado',
            self::Failed => 'Falhou',
        };
    }

    /** @return list<string> */
    public static function liveValues(): array
    {
        return [self::Pending->value, self::Active->value];
    }
}
