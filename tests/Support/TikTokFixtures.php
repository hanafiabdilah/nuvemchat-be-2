<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\TikTok\TikTokAuthClient;
use App\Services\Connection\TikTok\TikTokConfig;
use App\Services\TikTok\TikTokUrlProperty;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Shared setup for the TikTok publishing suites (a class, like InstagramFixtures). */
class TikTokFixtures
{
    public const ALL_PERMISSIONS = [
        'tiktok-posts.view',
        'tiktok-posts.create',
        'tiktok-posts.publish',
        'tiktok-posts.delete',
        'tiktok-comments.manage',
    ];

    public static function user(array $permissions = self::ALL_PERMISSIONS): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $role = Role::findOrCreate('tt-publisher-'.$tenant->id, 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    /** A connected TikTok account the user may reach, granted the post scopes unless told otherwise. */
    public static function connection(User $user, bool $postScopes = true, array $overrides = []): Connection
    {
        $scopes = $postScopes
            ? [...TikTokAuthClient::REQUIRED_SCOPES, ...TikTokAuthClient::POST_SCOPES]
            : TikTokAuthClient::REQUIRED_SCOPES;

        $connection = Connection::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'channel' => Channel::TikTok,
            'name' => 'Loja no TikTok',
            'color' => '#000000',
            'status' => ConnectionStatus::Active,
            'credentials' => [
                'access_token' => 'tt-token',
                'refresh_token' => 'tt-refresh',
                'business_id' => 'open-id-123',
                'username' => 'lojaaurora',
                'token_expires_at' => now()->addHours(12)->toDateTimeString(),
                'refresh_token_expires_at' => now()->addDays(20)->toDateTimeString(),
                'scope' => implode(',', $scopes),
            ],
        ], $overrides));

        $user->connections()->syncWithoutDetaching([$connection->id]);

        return $connection;
    }

    /** Mark the current media prefix as verified with TikTok. */
    public static function verifyPrefix(): void
    {
        Setting::set(TikTokConfig::KEY_URL_PROPERTY, json_encode([
            'url' => TikTokUrlProperty::currentPrefix(),
            'status' => 1,
        ]));
    }

    /** TikTok's envelope: HTTP 200, `code` 0 for success. */
    public static function ok(array $data = []): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['code' => 0, 'message' => 'OK', 'data' => $data]);
    }

    public static function refused(int $code, string $message): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['code' => $code, 'message' => $message, 'data' => []]);
    }

    /** All TikTok calls in one fake, routed by URL (Http::fake keeps the first match). */
    public static function fake(array $routes): void
    {
        Http::fake(function ($request) use ($routes) {
            foreach ($routes as $needle => $response) {
                if (str_contains($request->url(), $needle)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            return Http::response(['code' => 40007, 'message' => 'unrouted '.$request->url()]);
        });
    }
}
