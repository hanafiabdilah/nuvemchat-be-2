<?php

namespace App\Enums\Billing;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Manual = 'manual';

    /**
     * Statuses that grant the tenant access to the platform.
     */
    public function isUsable(): bool
    {
        return in_array($this, [
            self::Trialing,
            self::Active,
            self::Grace,
            self::Manual,
        ], true);
    }
}
