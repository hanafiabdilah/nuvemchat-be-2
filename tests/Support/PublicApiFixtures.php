<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\ApiKey;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Setup for the public API suite. A class rather than Pest helper functions,
 * which are global and collide across test files.
 */
final class PublicApiFixtures
{
    public static function owner(): User
    {
        $user = User::factory()->create(['whatsapp_verified_at' => now()]);
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->givePermissionTo(Permission::findOrCreate('api-keys.manage', 'web'));

        return $user->fresh();
    }

    /** A plain agent of the owner's workspace, optionally with one connection. */
    public static function agent(User $owner, ?Connection $connection = null, string $email = 'ana@example.com'): User
    {
        $agent = User::factory()->create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Ana',
            'email' => $email,
            'whatsapp_verified_at' => now(),
        ]);

        if ($connection) {
            $agent->connections()->syncWithoutDetaching([$connection->id]);
        }

        return $agent;
    }

    public static function connection(
        User $owner,
        Channel $channel = Channel::WhatsappApiway,
        string $name = 'WhatsApp Vendas',
        ConnectionStatus $status = ConnectionStatus::Active,
    ): Connection {
        return Connection::create([
            'tenant_id' => $owner->tenant_id,
            'channel' => $channel,
            'name' => $name,
            'color' => '#22c55e',
            'status' => $status,
        ]);
    }

    public static function key(User $owner, string $name = 'ProxyBR'): string
    {
        [, $plain] = ApiKey::issue($owner->tenant, $name, $owner);

        return $plain;
    }
}
