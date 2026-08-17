<?php

namespace App\Enums\Lead;

/**
 * How the lead got here. Kept apart from the channel (which is on the
 * connection) because "a person messaged us" and "we messaged a list" are
 * different origins even when both arrive over WhatsApp — and they convert at
 * very different rates, so a report that merges them is misleading.
 */
enum LeadSource: string
{
    /** The customer wrote first. The default, and the overwhelming majority. */
    case Inbound = 'inbound';

    /** An agent created the card by hand — a phone call, an event, a referral. */
    case Manual = 'manual';

    /** Opened by a campaign reaching out. */
    case Broadcast = 'broadcast';

    case Import = 'import';
}
