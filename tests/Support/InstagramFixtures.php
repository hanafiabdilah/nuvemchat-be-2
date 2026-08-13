<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Shared setup for the Instagram publishing suites. A class rather than loose
 * functions for the same reason as BroadcastFixtures: Pest loads every feature
 * file into one process, so top-level helpers would collide.
 */
class InstagramFixtures
{
    public const ALL_PERMISSIONS = [
        'instagram-posts.view',
        'instagram-posts.create',
        'instagram-posts.publish',
        'instagram-posts.delete',
        'instagram-comments.manage',
    ];

    public static function user(array $permissions = self::ALL_PERMISSIONS): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $role = Role::findOrCreate('ig-publisher-' . $tenant->id, 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * A connected Instagram account the user has been granted access to.
     *
     * The pivot row is the point: these users are not owners, so without it
     * every request would (correctly) 403 — connection access is a real
     * product rule here, not a UI filter.
     */
    public static function connection(User $user, array $overrides = []): Connection
    {
        $connection = Connection::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'channel' => Channel::Instagram,
            'name' => 'Loja oficial',
            'color' => '#E1306C',
            'status' => ConnectionStatus::Active,
            'credentials' => [
                'access_token' => 'ig-token',
                'instagram_account_id' => '17841400000000000',
                'user_id' => '9876543210',
                'username' => 'loja.oficial',
            ],
        ], $overrides));

        $user->connections()->syncWithoutDetaching([$connection->id]);

        return $connection;
    }

    /** A minimal valid composer payload for a single photo. */
    public static function imagePayload(array $overrides = []): array
    {
        return array_merge([
            'media_type' => 'image',
            'caption' => 'Novidade na loja',
            'items' => [
                ['url' => 'https://cdn.example.com/a.jpg', 'path' => 'instagram/1/a.jpg', 'media_type' => 'image'],
            ],
        ], $overrides);
    }

    public static function carouselPayload(int $count = 3): array
    {
        return [
            'media_type' => 'carousel',
            'caption' => 'Coleção nova',
            'items' => collect(range(1, $count))->map(fn (int $i) => [
                'url' => "https://cdn.example.com/{$i}.jpg",
                'path' => "instagram/1/{$i}.jpg",
                'media_type' => 'image',
            ])->all(),
        ];
    }
}
