<?php

namespace App\Jobs;

use App\Exceptions\ApiwayPartnerException;
use App\Models\ApiwaySubscription;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Creates the subscription at ProxyBR after payment (or included grant).
 * Safe to retry: the partner create is idempotent via external_ref, so a
 * crash between their commit and ours only replays the same subscription.
 */
class ProvisionApiwaySubscription implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300];

    public int $timeout = 120;

    public function __construct(public int $apiwaySubscriptionId) {}

    public function uniqueId(): string
    {
        return (string) $this->apiwaySubscriptionId;
    }

    public function handle(ApiwayService $apiway): void
    {
        $row = ApiwaySubscription::find($this->apiwaySubscriptionId);

        if (! $row) {
            return;
        }

        try {
            $apiway->provision($row);
        } catch (ApiwayPartnerException $e) {
            if (! $e->isRetriable()) {
                // provision() already marked the row failed + notified.
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProvisionApiwaySubscription exhausted retries', [
            'apiway_subscription_id' => $this->apiwaySubscriptionId,
            'error' => $e->getMessage(),
        ]);
    }
}
