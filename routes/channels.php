<?php

use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Every channel below is private: the events carry customer message bodies,
| phone numbers and connection metadata, so subscribing has to be authorized.
|
| The one exception is widget-session.{token}, which stays public and is
| therefore absent from this file. The widget SDK runs on third-party sites
| with no user to authenticate — its only credential is the session token,
| which is a v4 UUID that is also what authenticates its REST calls. Making
| the channel private would gate it on the very secret that already grants
| full access to that session, so it would add ceremony and no security.
|
*/

/**
 * Tenant-wide events that are not tied to one connection: billing, API Way
 * purchases, template approvals, campaign progress. Every member of the tenant
 * is entitled to these.
 */
Broadcast::channel('tenant-channel.{tenantId}', function (User $user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});

/**
 * Everything that carries conversation content — messages, thread state,
 * handoffs, transfers, group removals, connection status.
 *
 * Scoped per connection rather than per tenant so an agent only ever receives
 * the channels they were assigned in connection_user. Owners are allowed
 * through on the role check, because they deliberately hold no pivot rows.
 */
Broadcast::channel('tenant.{tenantId}.connection.{connectionId}', function (User $user, $tenantId, $connectionId) {
    if ((int) $user->tenant_id !== (int) $tenantId) {
        return false;
    }

    // Resolved tenant-scoped first, so canAccessConnection() cannot be talked
    // into approving another tenant's connection id for an owner.
    $connection = Connection::where('tenant_id', $user->tenant_id)->find($connectionId);

    return $connection !== null && $user->canAccessConnection($connection);
});

/**
 * Per-user channel. Used to tell a signed-in agent that their own connection
 * assignments changed, so a revoke takes effect within seconds instead of at
 * their next login.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});
