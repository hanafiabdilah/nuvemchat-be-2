<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Push\PushNotifier;
use App\Services\Push\PushResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * One push to one phone. Retried only when FCM said to try again (429, 5xx);
 * a dead token is deleted and a configuration failure is recorded on the row,
 * neither of which another attempt would change.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    // HTTP budget: OAuth exchange (10s) + send (10s) + one 401 re-send (10s).
    public int $timeout = 45;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    public function __construct(
        public int $deviceTokenId,
        public array $message,
    ) {}

    public function handle(PushNotifier $notifier): void
    {
        $device = DeviceToken::query()->with('user')->find($this->deviceTokenId);
        if (! $device) {
            // Logged out (or the token died) after the fan-out — nothing to do.
            return;
        }

        $result = $notifier->deliver($device, $this->message);

        if ($result->outcome === PushResult::RETRYABLE) {
            throw new RuntimeException('FCM asked to retry: '.$result->error);
        }
    }
}
