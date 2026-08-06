<?php

namespace App\Enums\Apiway;

enum ApiwaySubscriptionStatus: string
{
    /** Unit purchase awaiting payment — nothing exists at ProxyBR yet. */
    case PendingPayment = 'pending_payment';
    /** Paid (or included) — partner create call not confirmed yet. */
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    /** Provisioning failed permanently (e.g. no capacity) — needs manual follow-up. */
    case Failed = 'failed';

    /**
     * Terminal states: resources were revoked (or never existed) at ProxyBR.
     * A new purchase is the only way forward — partner renew refuses these.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Expired, self::Cancelled, self::Failed], true);
    }

    /** States whose instances count as owned/usable assets. */
    public function isLive(): bool
    {
        return in_array($this, [self::Provisioning, self::Active, self::Suspended], true);
    }
}
