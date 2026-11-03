<?php

namespace App\Enums\Reservation;

/** Who wrote the reservation down. */
enum ReservationSource: string
{
    /** The "AI agent with actions" node, in a conversation. */
    case Ai = 'ai';

    /** Somebody on the team, on the calendar — a phone call, a walk-in. */
    case Manual = 'manual';
}
