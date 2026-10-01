<?php

namespace App\Jobs;

use App\Services\Push\PushNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Picks who gets a push for one event and queues a send per phone. Ids only:
 * the conversation is re-read here, because between the event and this job an
 * agent may already have taken the thread.
 */
class FanOutPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // A second run would notify everybody twice.
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $type,
        public int $conversationId,
        public array $context = [],
    ) {}

    public function handle(PushNotifier $notifier): void
    {
        $notifier->fanOut($this->type, $this->conversationId, $this->context);
    }
}
