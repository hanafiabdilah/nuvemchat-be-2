<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A reservation was confirmed during an AI turn — the calendar had room and
 * the service confirms by itself, or the customer took the time the team
 * offered: take the node's "Reservation confirmed" output, if the author drew
 * one.
 *
 * A job for the same reason as RunAiToolsPaymentBranch: it is dispatched from
 * inside the turn, and must wait for the turn to send its reply first, so the
 * customer reads the AI's confirmation before the next step's message.
 */
class RunReservationConfirmedBranch implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 120;

    private const RETRY_SECONDS = 10;

    public function __construct(public int $reservationId) {}

    public function handle(): void
    {
        if (! (new FlowExecutor)->takeReservationConfirmedBranch($this->reservationId)) {
            $this->release(self::RETRY_SECONDS);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunReservationConfirmedBranch: the confirmation never reached the flow', [
            'reservation_id' => $this->reservationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
