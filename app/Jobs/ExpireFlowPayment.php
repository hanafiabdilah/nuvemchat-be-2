<?php

namespace App\Jobs;

use App\Models\FlowPayment;
use App\Services\Flow\FlowPaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The moment a charge's deadline passes.
 *
 * Armed by the payment node for just after the expiry it gave the gateway.
 * Safe to run late, twice, or after the payment already settled: settling is
 * idempotent, and a charge that is no longer pending is left alone. The
 * flow-payments:sync sweep covers a job that never ran at all.
 */
class ExpireFlowPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $paymentId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(FlowPaymentService $payments): void
    {
        $payment = FlowPayment::find($this->paymentId);

        if ($payment !== null && $payment->isPending()) {
            $payments->expire($payment);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ExpireFlowPayment: gave up; the sweep will settle it', [
            'flow_payment_id' => $this->paymentId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
