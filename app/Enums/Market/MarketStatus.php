<?php

namespace App\Enums\Market;

/**
 * Where a market is in its life.
 *
 * Only recorded for now. Gating signups on it (invitation codes during a soft
 * launch, no new workspaces while paused) belongs to the market module that
 * lets an admin open a country; until then there is exactly one market and it
 * is active.
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
