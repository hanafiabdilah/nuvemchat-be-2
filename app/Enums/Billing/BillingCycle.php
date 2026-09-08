<?php

namespace App\Enums\Billing;

enum BillingCycle: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Advance a given date by one billing cycle.
     */
    public function advance(\Carbon\CarbonInterface $from): \Carbon\CarbonInterface
    {
        return match ($this) {
            self::Daily => $from->copy()->addDay(),
            self::Monthly => $from->copy()->addMonth(),
            self::Yearly => $from->copy()->addYear(),
        };
    }


    /** Human label for UIs. */
    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }
}
