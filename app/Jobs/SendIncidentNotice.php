<?php

namespace App\Jobs;

use App\Models\IncidentDelivery;
use App\Services\Incident\IncidentSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The notice for a customer who just wrote in during an incident.
 *
 * A job rather than a send inside the webhook: an outage is the moment three
 * hundred customers write at once, and each inline send would hold a PHP
 * worker for a channel round trip — the platform answering 502 because one
 * workspace's servers are down.
 *
 * `$tries = 1`: a second attempt is not a second attempt at one message, it is
 * a second bubble in front of the customer if the first one did land.
 */
class SendIncidentNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $deliveryId) {}

    public function handle(IncidentSender $sender): void
    {
        $delivery = IncidentDelivery::find($this->deliveryId);

        if ($delivery) {
            $sender->send($delivery);
        }
    }
}
