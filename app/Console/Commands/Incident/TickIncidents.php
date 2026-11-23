<?php

namespace App\Console\Commands\Incident;

use App\Enums\Incident\DeliveryStatus;
use App\Enums\Incident\IncidentStatus;
use App\Jobs\RunIncidentOutreach;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Services\Incident\IncidentService;
use App\Services\Notification\WhatsappAlerts;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The minute hand for incidents.
 *
 *  - Starts the scheduled ones whose time has come.
 *  - Ends the ones whose time ran out. The live scope already stops answering
 *    the second ends_at passes; this writes it down and drops the notices the
 *    platform was still going to send on its own.
 *  - Tells the people who asked that an incident is about to end.
 *  - Marks failed the sends whose worker died mid-way — never re-sends them:
 *    one that timed out may have reached the customer.
 *  - Revives the paced pumps that stopped, for rows still waiting.
 */
class TickIncidents extends Command
{
    protected $signature = 'incidents:tick';

    protected $description = 'Start due incidents, end expired ones, warn before they end, and revive stalled sends';

    /** A send is one channel round trip; one still `sending` after this lost its worker. */
    private const STALE_CLAIM_MINUTES = 5;

    /** A pending row this old with no pump heartbeat has nobody driving it. */
    private const STALE_PENDING_MINUTES = 2;

    public function handle(IncidentService $incidents): int
    {
        Heartbeat::ping('incidents:tick');

        Incident::where('status', IncidentStatus::Scheduled)
            ->where('starts_at', '<=', now())
            ->orderBy('starts_at')
            ->get()
            ->each(fn (Incident $incident) => $incidents->activate($incident));

        Incident::where('status', IncidentStatus::Active)
            ->where('ends_at', '<=', now())
            ->get()
            ->each(fn (Incident $incident) => $incidents->expire($incident));

        $this->remind();
        $this->failStaleClaims();
        $this->revivePumps();

        return self::SUCCESS;
    }

    private function remind(): void
    {
        $minutes = max(1, (int) config('incidents.reminder_minutes', 30));

        Incident::live()
            ->whereNull('reminder_sent_at')
            ->where('ends_at', '<=', now()->addMinutes($minutes))
            ->get()
            // One that was opened for less time than the warning would be
            // warned about the moment it starts — that is noise, not a warning.
            ->filter(fn (Incident $incident) => ($incident->activated_at ?? $incident->created_at)
                ->diffInMinutes($incident->ends_at) > $minutes)
            ->each(function (Incident $incident) {
                $incident->forceFill(['reminder_sent_at' => now()])->save();
                WhatsappAlerts::incidentExpiring($incident);
            });
    }

    private function failStaleClaims(): void
    {
        IncidentDelivery::where('status', DeliveryStatus::Sending)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update([
                'status' => DeliveryStatus::Failed,
                'reason' => IncidentDelivery::REASON_INTERRUPTED,
                'updated_at' => now(),
            ]);
    }

    private function revivePumps(): void
    {
        IncidentDelivery::where('status', DeliveryStatus::Pending)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PENDING_MINUTES))
            ->whereNotNull('connection_id')
            ->select(['incident_id', 'connection_id', 'kind'])
            ->distinct()
            ->get()
            ->each(function (IncidentDelivery $row) {
                $kind = $row->kind->value;

                if (Cache::has(RunIncidentOutreach::heartbeatKey((int) $row->incident_id, (int) $row->connection_id, $kind))) {
                    return;
                }

                RunIncidentOutreach::dispatch((int) $row->incident_id, (int) $row->connection_id, $kind);
            });
    }
}
