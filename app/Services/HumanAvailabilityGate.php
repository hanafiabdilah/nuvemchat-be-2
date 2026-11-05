<?php

namespace App\Services;

use App\Enums\Connection\HumanAvailability;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Is there a person this connection can hand a customer to right now?
 *
 * Each connection chooses what answers it (`connections.human_availability`):
 *
 * - `service_hours` — the schedule, exactly as before this was a choice. Inside
 *   the hours a handoff reaches the queue even if nobody is signed in; outside
 *   them it never does, even if somebody is.
 * - `online_agents` — whether anybody who can open this connection is online.
 *   The schedule is ignored: an agent online at 23:00 takes the customer, and
 *   an empty office at 10:00 does not.
 *
 * "Online" is User::isOnline(): the agent's own switch says Online *and* their
 * dashboard is still sending heartbeats. The switch alone is not enough — an
 * agent who closes the laptop without flipping it would otherwise keep
 * customers queueing for a chair nobody is in.
 *
 * Only the AI → human handoff reads this (FlowExecutor::routeHandoff). The
 * Condition node's `service_hours.is_open` and the AI node's "AI only outside
 * service hours" mode are questions about the clock and keep asking
 * BusinessHours directly.
 */
class HumanAvailabilityGate
{
    public static function isAvailable(Connection $connection): bool
    {
        return match ($connection->humanAvailability()) {
            HumanAvailability::OnlineAgents => self::onlineAgents($connection)->exists(),
            HumanAvailability::ServiceHours => BusinessHours::isOpen($connection),
        };
    }

    /**
     * The people who could take a customer of this connection right now:
     * online, in this workspace, and able to open the connection — an owner
     * (who sees every connection, and has no pivot row to prove it) or an agent
     * the connection was given to.
     *
     * @return Builder<User>
     */
    public static function onlineAgents(Connection $connection): Builder
    {
        return User::query()
            ->where('tenant_id', $connection->tenant_id)
            ->online()
            ->where(function (Builder $query) use ($connection) {
                $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'owner'))
                    ->orWhereHas('connections', fn (Builder $connections) => $connections->where('connections.id', $connection->id));
            });
    }
}
