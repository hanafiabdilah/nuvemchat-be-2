<?php

namespace App\Http\Controllers\Api\TikTok;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Enums\TikTok\PostStatus;
use App\Http\Controllers\Api\TikTok\Concerns\ResolvesTikTokConnection;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\TikTokPost;
use App\Services\TikTok\TikTokAccountsClientFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The account picker: the workspace's connected TikTok accounts.
 *
 * They are the inbox connections themselves — a TikTok account is connected
 * once, in Connections, and gains its Posts and Comments here. That is the
 * difference from YouTube, which carries no inbox and is connected on its page.
 */
class TikTokAccountController extends Controller
{
    use ResolvesTikTokConnection;

    private const PROFILE_TTL_MINUTES = 60;

    public function __construct(
        private readonly TikTokAccountsClientFactory $clients,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $connections = Connection::where('tenant_id', $user->tenant_id)
            ->where('channel', Channel::TikTok)
            ->where('status', Status::Active)
            ->when(
                ! $user->canAccessAllConnections(),
                fn ($query) => $query->whereIn('id', $user->accessibleConnectionIds()),
            )
            ->orderBy('name')
            ->get();

        $pending = TikTokPost::query()
            ->whereIn('connection_id', $connections->pluck('id'))
            ->whereIn('status', [PostStatus::Scheduled, PostStatus::Queued, PostStatus::Publishing])
            ->selectRaw('connection_id, count(*) as aggregate')
            ->groupBy('connection_id')
            ->pluck('aggregate', 'connection_id');

        return response()->json([
            'data' => $connections->map(function (Connection $connection) use ($pending) {
                $client = $this->clients->for($connection);

                return [
                    'connection_id' => (string) $connection->id,
                    'name' => $connection->name,
                    'username' => $connection->credentials['username'] ?? null,
                    'color' => $connection->color,
                    // Only what a previous visit paid for: the card grid must
                    // not spend one call per card.
                    'profile' => Cache::get($this->profileKey($connection)),
                    'has_post_permissions' => $client->hasPostPermissions(),
                    'scheduled_count' => (int) ($pending[$connection->id] ?? 0),
                ];
            })->values(),
        ]);
    }

    /**
     * One account in full: its profile, and what its posts may be — the
     * privacy levels TikTok offers this account and the longest video it
     * takes, so the composer offers only what will be accepted.
     */
    public function show(Request $request, string $id)
    {
        $connection = $this->tiktokConnection($request, $id);
        $client = $this->clients->for($connection);
        $client->assertPostPermissions();

        $profile = $client->profile();
        Cache::put($this->profileKey($connection), $profile, now()->addMinutes(self::PROFILE_TTL_MINUTES));

        try {
            $settings = $client->settings();
        } catch (\Throwable $e) {
            // The composer falls back to TikTok's defaults; not worth failing
            // the account page over.
            Log::warning('Could not read TikTok post settings', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
            $settings = [];
        }

        return response()->json([
            'data' => [
                'connection_id' => (string) $connection->id,
                'name' => $connection->name,
                'color' => $connection->color,
                'profile' => $profile,
                'settings' => [
                    'privacy_level_options' => array_values($settings['privacy_level_options'] ?? ['PUBLIC_TO_EVERYONE']),
                    'comment_disabled' => (bool) ($settings['comment_disabled'] ?? false),
                    'duet_disabled' => (bool) ($settings['duet_disabled'] ?? false),
                    'stitch_disabled' => (bool) ($settings['stitch_disabled'] ?? false),
                    'max_video_post_duration_sec' => isset($settings['max_video_post_duration_sec']) ? (int) $settings['max_video_post_duration_sec'] : null,
                ],
            ],
        ]);
    }

    private function profileKey(Connection $connection): string
    {
        return "tiktok:profile:{$connection->id}";
    }
}
