<?php

namespace App\Enums\Market;

/**
 * Where a market is in its life.
 *
 * Recorded, set in the Back Office, and deliberately not enforced: signups are
 * not gated on it (decided when chat.pingly.id went live in parallel — nobody
 * signs up on a domain that isn't promoted). It says where a country is in its
 * launch; anything that should act on it has to be added on purpose.
 */
enum MarketStatus: string
{
    /** Being configured; meant only for the people setting it up. */
    case Draft = 'draft';

    /** Open by invitation. */
    case SoftLaunch = 'soft_launch';

    /** Open to anyone. */
    case Active = 'active';

    /** Closed to new workspaces while existing ones carry on. A market is never deleted. */
    case Paused = 'paused';
}
