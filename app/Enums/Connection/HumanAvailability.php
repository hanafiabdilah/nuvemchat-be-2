<?php

namespace App\Enums\Connection;

/**
 * What decides, on one connection, whether a customer can be handed to a
 * person (see App\Services\HumanAvailabilityGate).
 *
 * Only the handoff asks this. The schedule keeps its own readers — the
 * Condition node's `service_hours.is_open` and the AI node's "AI only outside
 * service hours" mode — because those questions are about the clock, and the
 * customer chose for them to stay that way.
 */
enum HumanAvailability: string
{
    /** The service hours decide; whether anybody is signed in does not. */
    case ServiceHours = 'service_hours';

    /** Anybody online who can open this connection decides; the clock does not. */
    case OnlineAgents = 'online_agents';
}
