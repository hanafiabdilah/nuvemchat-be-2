<?php

namespace App\Http\Controllers\Api\Youtube;

use App\Enums\Youtube\PostStatus;
use App\Events\YoutubeUpdated;
use App\Http\Controllers\Api\Youtube\Concerns\ResolvesYoutubeAccount;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\YoutubeAccount;
use App\Models\YoutubePost;
use App\Services\Media\MediaStorage;
use App\Services\Social\PostQueueSummary;
use App\Services\Youtube\YoutubeClientFactory;
use App\Services\Youtube\YoutubeConfig;
use App\Services\Youtube\YoutubeOAuth;
use App\Services\Youtube\YoutubePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The channels a workspace has connected — and connecting them.
 *
 * Connecting happens on the YouTube page itself, not in the Connections
 * wizard: a channel carries no conversations, so it has no place among the
 * inbox's connections (see the youtube_accounts migration).
 */
class YoutubeAccountController extends Controller
{
    use ResolvesYoutubeAccount;

    /** The same hour as Instagram's and TikTok's profile cache. */
    private const STATISTICS_TTL_MINUTES = 60;

    public function __construct(
        private readonly YoutubeClientFactory $clients,
        private readonly YoutubeOAuth $oauth,
    ) {}

    public function index(Request $request)
    {
        $accounts = YoutubeAccount::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('title')
            ->get();

        $pending = YoutubePost::query()
            ->whereIn('youtube_account_id', $accounts->pluck('id'))
            ->whereIn('status', PostStatus::pending())
            ->selectRaw('youtube_account_id, count(*) as aggregate')
            ->groupBy('youtube_account_id')
            ->pluck('aggregate', 'youtube_account_id');

        // Videos sent from here that YouTube has not made public yet. Not a
        // status of ours (they are Published), so they are counted apart from
        // the drafts and failures below.
        $scheduled = YoutubePost::query()
            ->whereIn('youtube_account_id', $accounts->pluck('id'))
            ->where('status', PostStatus::Published)
            ->where('scheduled_at', '>', now())
            ->selectRaw('youtube_account_id, count(*) as aggregate, min(scheduled_at) as next_at')
            ->groupBy('youtube_account_id')
            ->toBase()
            ->get()
            ->keyBy('youtube_account_id');

        $queue = PostQueueSummary::build(
            YoutubePost::query(),
            'youtube_account_id',
            $accounts->pluck('id'),
            scheduled: [],
            drafts: [PostStatus::Draft],
            failed: [PostStatus::Failed],
        );

        return response()->json([
            'data' => $accounts->map(function (YoutubeAccount $account) use ($pending, $scheduled, $queue) {
                $upcoming = $scheduled[$account->id] ?? null;

                return $this->card($account) + [
                    'pending_count' => (int) ($pending[$account->id] ?? 0),
                    'scheduled_count' => (int) ($upcoming->aggregate ?? 0),
                    'next_scheduled_at' => $upcoming?->next_at ? Carbon::parse($upcoming->next_at)->toIso8601String() : null,
                    'draft_count' => $queue[(string) $account->id]['draft_count'],
                    'failed_count' => $queue[(string) $account->id]['failed_count'],
                    // Only what an earlier lookup already paid for: each card
                    // asks for its own numbers once the list is on screen.
                    'statistics' => Cache::get($this->statisticsKey($account)),
                ];
            })->values(),
            // Whether the Connect button can work at all. A platform without a
            // Google client says so up front instead of opening a popup that
            // lands on a Google error page.
            'can_connect' => YoutubeConfig::isConfigured(),
        ]);
    }

    /**
     * The numbers behind one card: subscribers, videos, views. Cached per
     * channel and asked by each card on its own, so the list never waits on
     * Google and a channel that lost its grant only fails its own card.
     */
    public function statistics(Request $request, string $id)
    {
        $account = $this->youtubeAccount($request, $id);

        $statistics = Cache::remember(
            $this->statisticsKey($account),
            now()->addMinutes(self::STATISTICS_TTL_MINUTES),
            fn () => self::statisticsOf($this->clients->for($account)->channel()),
        );

        return response()->json(['data' => $statistics]);
    }

    /**
     * One channel in full, read live — and the stored identity refreshed with
     * it, so a renamed channel or a new avatar reaches the card list too.
     */
    public function show(Request $request, string $id)
    {
        $account = $this->youtubeAccount($request, $id);
        $channel = $this->clients->for($account)->channel();

        if ($channel !== []) {
            $account->update(array_filter([
                'title' => $channel['snippet']['title'] ?? null,
                'handle' => $channel['snippet']['customUrl'] ?? null,
                'thumbnail_url' => YoutubePresenter::thumbnail($channel['snippet']['thumbnails'] ?? []),
                'uploads_playlist_id' => $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null,
            ]));
        }

        $statistics = self::statisticsOf($channel);
        Cache::put($this->statisticsKey($account), $statistics, now()->addMinutes(self::STATISTICS_TTL_MINUTES));

        return response()->json([
            'data' => $this->card($account->fresh()) + [
                'statistics' => $statistics,
            ],
        ]);
    }

    /**
     * Where the Connect popup goes.
     *
     * The same endpoint reconnects a channel whose grant Google stopped
     * honouring: the callback matches on the channel id and updates the row.
     */
    public function authorizeUrl(Request $request)
    {
        if (! YoutubeConfig::isConfigured()) {
            return response()->json([
                'message' => 'A conexão com o YouTube ainda não está disponível nesta plataforma. Fale com o suporte.',
                'code' => 'youtube_not_configured',
            ], 503);
        }

        return response()->json([
            'data' => [
                'url' => $this->oauth->authorizeUrl((int) $request->user()->tenant_id, (int) $request->user()->id),
            ],
        ]);
    }

    /**
     * Disconnect a channel: forget it here, and hand the grant back to Google.
     *
     * Revoking is skipped while another workspace holds the same channel. A
     * Google grant belongs to the (Google account, OAuth client) pair, not to
     * our row, so revoking it for one workspace would silently disconnect the
     * other one too.
     *
     * Videos already on YouTube stay there — disconnecting is not deleting.
     */
    public function destroy(Request $request, string $id)
    {
        $account = $this->youtubeAccount($request, $id);

        if ($account->posts()->whereIn('status', [PostStatus::Queued, PostStatus::Uploading])->exists()) {
            throw new HttpException(409, 'A video is being uploaded to this channel right now. Wait for it to finish.');
        }

        $sharedElsewhere = YoutubeAccount::where('channel_id', $account->channel_id)
            ->whereKeyNot($account->id)
            ->exists();

        $refreshToken = $account->credentials['refresh_token'] ?? null;

        DB::transaction(function () use ($account) {
            $account->posts()->whereNotNull('video_path')->get()->each(function (YoutubePost $post) {
                MediaStorage::disk()->delete($post->video_path);
            });

            $account->delete();
        });

        if (! $sharedElsewhere && $refreshToken) {
            $this->oauth->revoke($refreshToken);
        }

        AuditLog::record('youtube.channel_disconnected', "Disconnected YouTube channel {$account->title}", [
            'channel_id' => $account->channel_id,
        ]);

        broadcast(new YoutubeUpdated((int) $account->tenant_id));

        return response()->json(['message' => 'YouTube channel disconnected.']);
    }

    /**
     * @return array{subscriber_count: ?int, video_count: int, view_count: int}
     */
    private static function statisticsOf(array $channel): array
    {
        $stats = $channel['statistics'] ?? [];

        return [
            // Hidden when the channel chose to hide it.
            'subscriber_count' => ($stats['hiddenSubscriberCount'] ?? false) ? null : (int) ($stats['subscriberCount'] ?? 0),
            'video_count' => (int) ($stats['videoCount'] ?? 0),
            'view_count' => (int) ($stats['viewCount'] ?? 0),
        ];
    }

    private function statisticsKey(YoutubeAccount $account): string
    {
        return "youtube:statistics:{$account->id}";
    }

    /** @return array<string, mixed> */
    private function card(YoutubeAccount $account): array
    {
        return [
            'id' => (string) $account->id,
            'channel_id' => $account->channel_id,
            'title' => $account->title,
            'handle' => $account->handle,
            'thumbnail_url' => $account->thumbnail_url,
            'url' => $account->watchUrl(),
            'status' => $account->status,
            'connected_at' => $account->connected_at?->toIso8601String(),
        ];
    }
}
