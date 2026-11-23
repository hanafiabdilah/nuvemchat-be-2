<?php

namespace App\Jobs;

use App\Enums\Incident\DeliveryKind;
use App\Enums\Incident\DeliverySource;
use App\Enums\Incident\DeliveryStatus;
use App\Models\Connection;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Services\Incident\IncidentEvents;
use App\Services\Incident\IncidentSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * The paced half of an incident, for one connection: the customers who wrote
 * shortly before it was switched on, and "it is fixed" when it is resolved.
 *
 * Same shape as the campaign pump (RunBroadcastJob) and for the same reasons:
 * a small batch, then a re-dispatch with the delay that holds the channel's
 * campaign rate, so nothing sleeps in a worker and an incident that ends
 * stops its notices within one batch. Two hundred messages fired in one
 * second from an unofficial WhatsApp client are exactly the pattern the
 * channel's pace exists to avoid.
 *
 * Each row is claimed by IncidentSender itself, so the watchdog reviving a
 * pump that was only slow cannot make two of them send to one person.
 */
class RunIncidentOutreach implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $incidentId,
        public int $connectionId,
        public string $kind,
    ) {
        $this->onQueue((string) config('incidents.outreach_queue', 'broadcasts'));
    }

    public static function heartbeatKey(int $incidentId, int $connectionId, string $kind): string
    {
        return "incident-outreach:{$incidentId}:{$connectionId}:{$kind}";
    }

    public function handle(IncidentSender $sender): void
    {
        $incident = Incident::find($this->incidentId);
        $connection = Connection::find($this->connectionId);
        $kind = DeliveryKind::from($this->kind);

        if (! $incident || ! $connection) {
            return;
        }

        Cache::put(self::heartbeatKey($incident->id, $connection->id, $kind->value), now()->timestamp, 600);

        // Notices the platform starts on its own stop with the incident. One a
        // customer triggered by writing still goes: it was decided while the
        // incident was on, and this is only here because its own job was lost.
        if ($kind === DeliveryKind::Notice && ! $incident->isLive()) {
            $this->pending($incident, $connection, $kind)
                ->where('source', '!=', DeliverySource::Reactive)
                ->update([
                    'status' => DeliveryStatus::Skipped,
                    'reason' => IncidentDelivery::REASON_INCIDENT_OVER,
                    'updated_at' => now(),
                ]);

            IncidentEvents::changed($incident);
        }

        $rate = max(1, $connection->channel->broadcastDefaultRatePerMinute());
        $batchSize = (int) max(1, min(25, ceil($rate / 6)));

        // Each AI-written notice is a model round trip; a campaign-sized batch
        // of them would outlive the job's timeout.
        if ($kind === DeliveryKind::Notice && $incident->writesWithAi()) {
            $batchSize = min($batchSize, 5);
        }
        $delay = (int) max(1, round($batchSize / $rate * 60));

        $batch = $this->pending($incident, $connection, $kind)
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        foreach ($batch as $index => $delivery) {
            if ($index > 0 && $connection->channel->broadcastNeedsSendJitter()) {
                usleep(random_int(500_000, 2_500_000));
            }

            $sender->send($delivery);
        }

        IncidentEvents::changed($incident, throttled: true);

        if ($this->pending($incident, $connection, $kind)->exists()) {
            self::dispatch($incident->id, $connection->id, $kind->value)->delay(now()->addSeconds($delay));

            return;
        }

        Cache::forget(self::heartbeatKey($incident->id, $connection->id, $kind->value));
        IncidentEvents::changed($incident);
    }

    private function pending(Incident $incident, Connection $connection, DeliveryKind $kind)
    {
        return IncidentDelivery::where('incident_id', $incident->id)
            ->where('connection_id', $connection->id)
            ->where('kind', $kind)
            ->where('status', DeliveryStatus::Pending);
    }
}
