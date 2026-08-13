<?php

use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Give every existing agent explicit access to the connections they were
 * already able to read.
 *
 * `connection_user` has existed for a while but only ever gated the connections
 * *list*; conversations and messages were filtered by tenant alone, and the
 * realtime channel was tenant-wide and public. So an agent with zero pivot rows
 * still saw the entire tenant inbox, and owners were never given rows at all.
 *
 * Now that the read paths and the broadcast channels honour the pivot, an agent
 * with zero rows would see nothing. Backfilling to "all connections in my
 * tenant" keeps every working account working — it changes no one's effective
 * access today — and turns an implicit grant into an explicit one the owner can
 * narrow per agent from Agents & Roles.
 *
 * Skipped on purpose:
 * - owners (canAccessAllConnections() answers by role; rows would go stale as
 *   soon as a new connection is created),
 * - platform/Back Office users (tenant_id is null — they never touch this API),
 * - agents who already have rows, whose owner has made a deliberate choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereNotNull('tenant_id')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'owner'))
            ->whereDoesntHave('connections')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $connectionIds = Connection::where('tenant_id', $user->tenant_id)->pluck('id');

                    if ($connectionIds->isNotEmpty()) {
                        $user->connections()->syncWithoutDetaching($connectionIds);
                    }
                }
            });
    }

    public function down(): void
    {
        // Not reversible: a backfilled row is indistinguishable from one an
        // owner assigned by hand, and deleting the wrong ones would lock agents
        // out of their inbox. Reverting the access model means reverting the
        // code, not the data.
    }
};
