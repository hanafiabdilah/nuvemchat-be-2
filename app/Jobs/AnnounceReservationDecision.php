<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Something the customer is waiting to hear about their reservation happened
 * — the team answered it, or its payment arrived or ran out: let the AI tell
 * them, and — for a confirmation — take the node's "Reservation confirmed"
 * output.
 *
 * Every check lives in FlowExecutor::announceReservationDecision. It waits out
 * an AI turn in flight (the same per-conversation lock), and never sends
 * twice: a retry only happens while the lock is held, before anything is sent.
 */
class AnnounceReservationDecision implements ShouldQueue
{
    use Queueable;

    /** Spent waiting out a turn in flight, like RunAiToolsPaymentBranch. */
    public int $tries = 20;

    /** Room for one charge, one model call and the send that follows it. */
    public int $timeout = 240;

    private const RETRY_SECONDS = 10;

    public function __construct(public int $reservationId, public string $action) {}

    public function handle(): void
    {
        if (! (new FlowExecutor)->announceReservationDecision($this->reservationId, $this->action)) {
            $this->release(self::RETRY_SECONDS);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('AnnounceReservationDecision: the customer was never told about their reservation', [
            'reservation_id' => $this->reservationId,
            'action' => $this->action,
            'error' => $exception?->getMessage(),
        ]);
    }
}
