<?php

namespace App\Jobs;

use App\Services\Notification\WhatsappAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The end of a flow-failure window: one WhatsApp alert for every conversation
 * a connection's flows stopped in since the first failure. See
 * WhatsappAlerts::flowFailed().
 */
class SendFlowFailureAlert implements ShouldQueue
{
    use Queueable;

    /** A second attempt would report a window that has already been reset. */
    public int $tries = 1;

    public function __construct(public int $connectionId) {}

    public function handle(): void
    {
        WhatsappAlerts::sendFlowFailures($this->connectionId);
    }
}
