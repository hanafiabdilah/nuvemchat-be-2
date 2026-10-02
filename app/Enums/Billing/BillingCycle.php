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

    /**
     * Ordering by length, for deciding whether a plan change commits the
     * customer to more (an upgrade, applied now) or less (applied at period end).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Monthly => 2,
            self::Yearly => 3,
        };
    }

    /**
     * The same cycle in Mercado Pago preapproval terms — which only knows days
     * and months, so a year is twelve of them.
     *
     * @return array{frequency: int, frequency_type: string}
     */
    public function mercadoPagoFrequency(): array
    {
        return match ($this) {
            self::Daily => ['frequency' => 1, 'frequency_type' => 'days'],
            self::Monthly => ['frequency' => 1, 'frequency_type' => 'months'],
            self::Yearly => ['frequency' => 12, 'frequency_type' => 'months'],
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
