<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * The realtime channel names, in one place.
 *
 * They have to match routes/channels.php exactly — a typo here does not fail
 * loudly, it just produces a channel nobody is authorized for (or, worse before
 * these were private, one anybody could join). Keeping both sides pointed at
 * these two methods is what makes that impossible to get wrong silently.
 */
final class Channels
{
    /**
     * Tenant-wide events that are not about a single connection: billing,
     * API Way purchases, template approvals, campaign progress.
     */
    public static function tenant(int|string $tenantId): PrivateChannel
    {
        return new PrivateChannel('tenant-channel.'.$tenantId);
    }

    /**
     * Everything carrying conversation content. Scoped to one connection so an
     * agent only receives the inboxes they were assigned.
     */
    public static function connection(int|string $tenantId, int|string $connectionId): PrivateChannel
    {
        return new PrivateChannel('tenant.'.$tenantId.'.connection.'.$connectionId);
    }
}
