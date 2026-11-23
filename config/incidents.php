<?php

/*
|--------------------------------------------------------------------------
| Incidents
|--------------------------------------------------------------------------
| A workspace telling everyone who writes in that something is broken, once
| per conversation (App\Services\Incident). Nothing here changes behaviour
| for a workspace that never opens an incident.
*/

return [

    // Where the reply to a customer who just wrote goes. Its own job, never
    // inline in the webhook: three hundred customers writing in the same
    // minute is exactly what an outage looks like, and sending inline would
    // hold one PHP worker per message for a channel round trip.
    'queue' => env('INCIDENT_QUEUE', 'default'),

    // Where the paced sends go — the customers who wrote just before the
    // incident was switched on, and the "it is fixed" message. Rate-limited
    // per connection like a campaign, so it rides the campaign worker.
    'outreach_queue' => env('INCIDENT_OUTREACH_QUEUE', 'broadcasts'),

    // How long before an incident runs out the people who asked for it are
    // told on WhatsApp (WhatsappAlerts::INCIDENT_EXPIRING).
    'reminder_minutes' => (int) env('INCIDENT_REMINDER_MINUTES', 30),

    // The most conversations "also tell who wrote in the last N minutes" will
    // reach in one activation. A ceiling, not a target: past this the paced
    // send would still be running when the incident is over.
    'retro_limit' => (int) env('INCIDENT_RETRO_LIMIT', 2000),

];
