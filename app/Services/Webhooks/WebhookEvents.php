<?php

namespace App\Services\Webhooks;

/**
 * The events an endpoint can subscribe to. Mirrored in the dashboard
 * (types/webhook.ts) — the API validates against this list.
 */
final class WebhookEvents
{
    /** An agent took the lead's conversation, or became the lead's responsible. */
    public const LEAD_ASSIGNED = 'lead.assigned';

    /** The card moved between open stages (or was reopened). */
    public const LEAD_STAGE_CHANGED = 'lead.stage_changed';

    public const LEAD_WON = 'lead.won';

    public const LEAD_LOST = 'lead.lost';

    /** Sent only by the "send test" button; not subscribable. */
    public const PING = 'ping';

    public const SUBSCRIBABLE = [
        self::LEAD_ASSIGNED,
        self::LEAD_STAGE_CHANGED,
        self::LEAD_WON,
        self::LEAD_LOST,
    ];
}
