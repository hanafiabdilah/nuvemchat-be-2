<?php

namespace App\Services\Incident;

use App\Events\IncidentUpdated;
use App\Models\Incident;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Telling open dashboards an incident moved.
 *
 * Throttled for the one source that fires in bursts — a notice per customer
 * who writes in — because an outage is three hundred of those in a minute and
 * the banner only needs to re-read its counters every few seconds. Changes a
 * person made (activate, extend, resolve) always go out at once.
 */
final class IncidentEvents
{
    private const THROTTLE_SECONDS = 5;

    public static function changed(Incident $incident, bool $throttled = false): void
    {
        if ($throttled && ! Cache::add("incident-updated:{$incident->id}", true, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            broadcast(new IncidentUpdated((int) $incident->tenant_id, (int) $incident->id, $incident->status->value));
        } catch (\Throwable $th) {
            // The banner is decoration over what the incident does; a socket
            // that is down must not stop a notice from going out.
            Log::warning('IncidentEvents: could not broadcast', ['incident_id' => $incident->id, 'error' => $th->getMessage()]);
        }
    }
}
