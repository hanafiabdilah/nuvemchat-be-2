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

    /** One user's own channel (routes/channels.php: App.Models.User.{id}). */
    public static function user(int|string $userId): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$userId);
    }

    /**
     * Where an event carrying a conversation's content goes.
     *
     * Normally the connection channel. An exclusive thread instead goes to the
     * private channel of each person allowed to read it (its assignee and the
     * workspace's owners): the connection channel is shared by every agent of
     * the inbox, and Reverb has no way to hand one subscriber a frame and
     * withhold it from the next.
     *
     * @return array<int, PrivateChannel>
     */
    public static function forConversationContent(\App\Models\Conversation $conversation): array
    {
        $connection = $conversation->getRelationValue('connection') ?? $conversation->connection()->first();

        if (! $conversation->isExclusive()) {
            return [self::connection($connection->tenant_id, $connection->id)];
        }

        return $conversation->exclusiveReaderIds()
            ->map(fn (int $id) => self::user($id))
            ->all();
    }
}
