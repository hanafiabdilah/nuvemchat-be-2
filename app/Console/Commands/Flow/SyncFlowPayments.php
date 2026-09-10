<?php

namespace App\Console\Commands\Flow;

use App\Enums\Flow\FlowPaymentStatus;
use App\Models\FlowPayment;
use App\Services\Flow\FlowPaymentService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The safety net under payment nodes.
 *
 * Webhooks are how a payment is confirmed in real time; this is how it is
 * confirmed when one never arrives — an OpenPix account whose webhook could not
 * be registered, a delivery the provider gave up on, a queue worker that was
 * down when the expiry job was due. Two passes, both capped:
 *
 *  1. Overdue charges still pending: asked one last time, then called unpaid.
 *  2. Open charges not checked recently: polled, so a missed webhook costs
 *     minutes rather than leaving a customer who paid talking to a bot that
 *     does not know it.
 *
 * Without it a flow can wait forever on a payment — hence the heartbeat.
 */
class SyncFlowPayments extends Command
{
    protected $signature = 'flow-payments:sync {--limit=100 : Most charges touched per pass, per kind}';

    protected $description = 'Confirm flow payments whose webhook never arrived and expire the unpaid ones';

    /** Young charges are polled often; a charge open for hours is polled less. */
    private const POLL_EVERY_MINUTES = 2;

    private const POLL_EVERY_MINUTES_AFTER_AN_HOUR = 10;

    public function handle(FlowPaymentService $payments): int
    {
        Heartbeat::ping('flow-payments:sync');

        $limit = max(1, (int) $this->option('limit'));
        $expired = 0;
        $polled = 0;

        $overdue = FlowPayment::query()
            ->where('status', FlowPaymentStatus::Pending)
            ->where('expires_at', '<=', now()->subMinute())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        foreach ($overdue as $payment) {
            try {
                $payments->expire($payment);
                $expired++;
            } catch (\Throwable $e) {
                Log::warning('flow-payments:sync could not expire a charge', [
                    'flow_payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $open = FlowPayment::query()
            ->where('status', FlowPaymentStatus::Pending)
            ->where('expires_at', '>', now())
            ->where('created_at', '<=', now()->subMinute())
            ->where(function ($query) {
                $query->whereNull('last_checked_at')
                    ->orWhere(fn ($young) => $young
                        ->where('created_at', '>', now()->subHour())
                        ->where('last_checked_at', '<=', now()->subMinutes(self::POLL_EVERY_MINUTES)))
                    ->orWhere('last_checked_at', '<=', now()->subMinutes(self::POLL_EVERY_MINUTES_AFTER_AN_HOUR));
            })
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();

        foreach ($open as $payment) {
            try {
                $payments->refresh($payment);
                $polled++;
            } catch (\Throwable $e) {
                Log::warning('flow-payments:sync could not poll a charge', [
                    'flow_payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$expired}, polled {$polled}.");

        return self::SUCCESS;
    }
}
