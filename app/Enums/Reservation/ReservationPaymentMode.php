<?php

namespace App\Enums\Reservation;

/**
 * What a service asks for before a reservation is confirmed.
 *
 * `none` confirms without money. `deposit` and `full` charge through the
 * payment account of the "AI agent with actions" node that took the booking,
 * and the booking is confirmed when the money arrives. One the team wrote
 * down records the amount and whether it was paid.
 */
enum ReservationPaymentMode: string
{
    case None = 'none';
    case Deposit = 'deposit';
    case Full = 'full';
}
