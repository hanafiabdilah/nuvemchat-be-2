<?php

namespace App\Services\Reservations;

use RuntimeException;

/**
 * A time that cannot be booked, with the reason in
 * ReservationAvailability::REASON_* — what the dashboard shows the team
 * before it offers "save anyway".
 */
final class SlotUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('reservations.problems.'.$reason));
    }
}
