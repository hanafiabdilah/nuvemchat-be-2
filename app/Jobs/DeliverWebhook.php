<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one webhook delivery, retrying for about seven hours.
 *
 * The backoff grows because the usual cause of a refused delivery is the
 * receiver being down or mid-deploy, and hammering it every minute helps
 * nobody. After the last attempt the row is marked failed and stays visible in
 * the endpoint's delivery log.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 30;

    public function __construct(
        public int $deliveryId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (! $delivery || $delivery->status !== WebhookDelivery::PENDING) {
            return;
        }

        if ($dispatcher->deliver($delivery) === WebhookDispatcher::RETRY) {
            throw new \RuntimeException("Webhook delivery {$delivery->id} was not accepted; it will be retried.");
        }
    }

    public function failed(?\Throwable $exception): void
    {
        WebhookDelivery::whereKey($this->deliveryId)
            ->where('status', WebhookDelivery::PENDING)
            ->update(['status' => WebhookDelivery::FAILED]);
    }
}
