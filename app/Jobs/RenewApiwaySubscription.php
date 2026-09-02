<?php

namespace App\Jobs;

use App\Exceptions\ApiwayPartnerException;
use App\Models\ApiwaySubscription;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Renews a subscription at ProxyBR after the renewal was paid (or, for
 * plan-included rows, granted). Idempotent via the Idempotency-Key passed in
 * — retries with the same key never extend twice.
 */
class RenewApiwaySubscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300];

    public int $timeout = 120;

    public function __construct(
        public int $apiwaySubscriptionId,
        public string $idempotencyKey,
        public ?string $cycle = null,
    ) {}

    public function handle(ApiwayService $apiway): void
    {
        $row = ApiwaySubscription::find($this->apiwaySubscriptionId);

        if (! $row || $row->status->isTerminal()) {
            return;
        }

        try {
            $apiway->renew($row, $this->idempotencyKey, $this->cycle);
        } catch (ApiwayPartnerException $e) {
            if (! $e->isRetriable()) {
                // invalid_state etc. — renew() already resynced local state.
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RenewApiwaySubscription exhausted retries', [
            'apiway_subscription_id' => $this->apiwaySubscriptionId,
            'idempotency_key' => $this->idempotencyKey,
            'error' => $e->getMessage(),
        ]);

        // The renewal was charged before this job ran, so giving up owes the
        // customer their money back. `failed()` is the only hook that knows the
        // attempt is over rather than between retries.
        //
        // The idempotency key IS the ledger reference for balance-paid renewals
        // — one string for one cycle, deliberately shared so the partner's
        // "charge once" and ours cannot drift. Legacy keys (invoice- and
        // card-paid renewals) match no ledger row and reverse nothing, which is
        // right: that money was never in the wallet.
        $row = ApiwaySubscription::find($this->apiwaySubscriptionId);

        if ($row) {
            try {
                app(ApiwayService::class)->reverseRenewalCharge($row, $this->idempotencyKey);
            } catch (\Throwable $reversalError) {
                // Never let the refund's failure bury the renewal's: this hook
                // is the last place either problem gets recorded.
                Log::error('Failed to reverse an apiway renewal charge', [
                    'apiway_subscription_id' => $this->apiwaySubscriptionId,
                    'reference' => $this->idempotencyKey,
                    'error' => $reversalError->getMessage(),
                ]);
            }
        }
    }
}
