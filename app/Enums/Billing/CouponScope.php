<?php

namespace App\Enums\Billing;

/**
 * What a coupon is spent on. One coupon, one scope: a plan coupon takes a
 * percentage off the subscription's charges, a top-up coupon adds a percentage
 * on top of a balance top-up as bonus credit.
 *
 * Kept apart rather than one coupon "for everything" because the two are not
 * the same money. A plan discount lowers what the customer pays; a top-up
 * coupon never does — the customer pays the amount they chose and the bonus
 * lands in the wallet as its own ledger row, so the invoice still equals the
 * money that arrived.
 */
enum CouponScope: string
{
    case Plan = 'plan';
    case Topup = 'topup';

    /** The highest percentage a coupon of this scope may carry. */
    public function maxPercent(): int
    {
        // A plan charge of zero is one no gateway takes, so a plan coupon
        // always leaves something to charge. A top-up bonus has no such floor.
        return $this === self::Plan ? 90 : 100;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $scope) => $scope->value, self::cases());
    }
}
