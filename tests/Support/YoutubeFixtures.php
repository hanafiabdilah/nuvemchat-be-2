<?php

namespace Tests\Support;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Services\Youtube\YoutubeConfig;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Shared setup for the YouTube suites. A class, like InstagramFixtures, so the
 * helpers do not collide with other files' in Pest's single process.
 */
class YoutubeFixtures
{
    public const ALL_PERMISSIONS = [
        'youtube-accounts.manage',
        'youtube-posts.view',
        'youtube-posts.create',
        'youtube-posts.publish',
        'youtube-posts.delete',
        'youtube-comments.manage',
    ];

    public const CHANNEL_ID = 'UCabcdefghijklmnopqrstuv';

    public static function user(array $permissions = self::ALL_PERMISSIONS): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $role = Role::findOrCreate('yt-publisher-'.$tenant->id, 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    public static function account(User $user, array $overrides = []): YoutubeAccount
    {
        return YoutubeAccount::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'connected_by' => $user->id,
            'channel_id' => self::CHANNEL_ID,
            'title' => 'Loja Aurora',
            'handle' => '@lojaaurora',
            'uploads_playlist_id' => 'UUabcdefghijklmnopqrstuv',
            'credentials' => [
                'access_token' => 'yt-access',
                'refresh_token' => 'yt-refresh',
                'token_expires_at' => now()->addHour()->toIso8601String(),
                'scope' => implode(' ', YoutubeConfig::SCOPES),
            ],
            'status' => YoutubeAccount::STATUS_ACTIVE,
            'connected_at' => now(),
        ], $overrides));
    }

    public static function configureClient(): void
    {
        Setting::set(YoutubeConfig::KEY_CLIENT_ID, 'client-123.apps.googleusercontent.com');
        Setting::set(YoutubeConfig::KEY_CLIENT_SECRET, 'GOCSPX-secret');
    }

    /** A videos.list item as Google returns it. */
    public static function video(string $id, array $status = [], array $snippet = []): array
    {
        return [
            'id' => $id,
            'snippet' => array_merge([
                'channelId' => self::CHANNEL_ID,
                'title' => "Video {$id}",
                'description' => 'Descrição',
                'categoryId' => '22',
                'publishedAt' => '2026-10-01T12:00:00Z',
                'thumbnails' => ['high' => ['url' => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg"]],
            ], $snippet),
            'status' => array_merge([
                'privacyStatus' => 'public',
                'uploadStatus' => 'processed',
                'embeddable' => true,
                'license' => 'youtube',
                'publicStatsViewable' => true,
            ], $status),
            'statistics' => ['viewCount' => '120', 'likeCount' => '8', 'commentCount' => '3'],
            'contentDetails' => ['duration' => 'PT1M30S'],
        ];
    }

    /** All Google calls in one fake, routed by URL — Http::fake keeps the first matching answer. */
    public static function fake(array $routes): void
    {
        Http::fake(function ($request) use ($routes) {
            foreach ($routes as $needle => $response) {
                if (str_contains($request->url(), $needle)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            return Http::response(['error' => ['code' => 404, 'message' => 'unrouted '.$request->url()]], 404);
        });
    }
}
