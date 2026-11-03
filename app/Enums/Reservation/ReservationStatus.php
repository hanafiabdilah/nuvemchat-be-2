<?php

namespace App\Enums\Reservation;

/**
 * Where a reservation stands.
 *
 *  - `pending`          — waiting for the team: the service asks them to
 *                         confirm, or something gave pause (ReviewReason).
 *  - `offered`          — the team offered another time; waiting for the
 *                         customer.
 *  - `awaiting_payment` — accepted, held until its charge is paid or runs out
 *                         (`hold_expires_at`).
 *  - `confirmed`        — it will happen.
 *  - `completed` / `no_show` — the team's record of how it went.
 *  - `declined`         — the team said no.
 *  - `cancelled` / `expired` — it will not happen; the slot is free again.
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Offered = 'offered';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * Statuses that take a place on the calendar. A request waiting for the
     * team holds its time — two customers must not both be told "the team will
     * confirm" for the last table. An `awaiting_payment` row only holds while
     * its hold lasts (Reservation::scopeHoldingCapacity). An `offered` one
     * holds nothing: the time asked for was turned down, and the offered time
     * is checked again when the customer takes it.
     *
     * @return list<string>
     */
    public static function holdingValues(): array
    {
        return [
            self::Pending->value,
            self::AwaitingPayment->value,
            self::Confirmed->value,
            self::Completed->value,
            self::NoShow->value,
        ];
    }

    /**
     * Still in play: waiting on someone, or going to happen. What the inbox
     * marks, the customer may ask about, and a new request is checked against
     * for duplicates.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Offered->value, self::AwaitingPayment->value, self::Confirmed->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    /** The team may still accept, decline or offer another time. */
    public function awaitsDecision(): bool
    {
        return in_array($this, [self::Pending, self::Offered], true);
    }

    /** It will not happen: nothing about it can change any more but a note. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Declined, self::Cancelled, self::Expired], true);
    }
}
