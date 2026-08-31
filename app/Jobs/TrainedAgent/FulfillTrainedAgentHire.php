<?php

namespace App\Jobs\TrainedAgent;

use App\Enums\TrainedAgent\HireStatus;
use App\Models\TrainedAgentHire;
use App\Services\TrainedAgent\TrainedAgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Copy a bought/granted blueprint into the tenant's AI Hub workspace.
 *
 * Queued because the fork is a dozen or more calls to the hub — far too much
 * to hold a checkout request open for, and absolutely too much to do inside a
 * MercadoPago webhook (the API Way lesson: never partner HTTP inside the
 * webhook transaction).
 *
 * Takes the id rather than the model so a retry always reads current state:
 * the tenant may have abandoned the hire between attempts.
 */
class FulfillTrainedAgentHire implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Generous: a big blueprint is many sequential hub round-trips. */
    public int $timeout = 300;

    public function __construct(public int $hireId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TrainedAgentService $service): void
    {
        $hire = TrainedAgentHire::find($this->hireId);

        if (! $hire) {
            return;
        }

        // Already done, or the tenant walked away while this sat in the queue.
        if ($hire->status !== HireStatus::Provisioning) {
            Log::info('Trained agent fulfilment skipped: not provisioning', [
                'hire_id' => $hire->id,
                'status' => $hire->status->value,
            ]);

            return;
        }

        $service->fulfill($hire);
    }

    /**
     * Out of retries. The hire is marked failed here rather than inside
     * fulfil(), so a transient hub hiccup on attempt one does not present
     * itself to the tenant as a purchase that fell over.
     */
    public function failed(\Throwable $e): void
    {
        $hire = TrainedAgentHire::find($this->hireId);

        if (! $hire || $hire->status !== HireStatus::Provisioning) {
            return;
        }

        app(TrainedAgentService::class)->markFailed($hire, $e->getMessage());
    }
}
