<?php

namespace App\Jobs;

use App\Services\Reservations\ReservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Remind the operators, once, of a request still waiting for them
 * (`reservations.review_reminder_minutes` after it came in). Nothing when it
 * has been answered since.
 */
class RemindReservationReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $reservationId) {}

    public function handle(ReservationService $reservations): void
    {
        $reservations->remindIfStillPending($this->reservationId);
    }
}
