<?php

namespace App\Http\Controllers\Api\Instagram;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Enums\Instagram\PostStatus;
use App\Http\Controllers\Api\Instagram\Concerns\ResolvesInstagramConnection;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\InstagramPost;
use App\Services\Instagram\InstagramGraphClientFactory;
use App\Services\Social\PostQueueSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The account picker: which Instagram accounts this user can publish to.
 *
 * Only connected accounts appear. A Pending or Inactive connection has no
 * usable token, so listing it would only produce a card that fails the moment
 * it is opened.
 */
class InstagramAccountController extends Controller
{
    use ResolvesInstagramConnection;

    /** Long enough to keep the card grid off the Graph API, short enough that a renamed account or a follower count catches up the same session. */
    private const PROFILE_TTL_MINUTES = 60;

    public function __construct(
        private readonly InstagramGraphClientFactory $clients,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $connections = Connection::where('tenant_id', $user->tenant_id)
            ->where('channel', Channel::Instagram)
            ->where('status', Status::Active)
            ->when(
                ! $user->canAccessAllConnections(),
                fn ($query) => $query->whereIn('id', $user->accessibleConnectionIds()),
            )
            ->orderBy('name')
            ->get();

        // One grouped query for the whole list, never one per card. "Scheduled"
        // is everything on its way that has not landed: queued and publishing
        // included, as the count always was.
        $queue = PostQueueSummary::build(
            InstagramPost::query(),
            'connection_id',
            $connections->pluck('id'),
            scheduled: [PostStatus::Scheduled, PostStatus::Queued, PostStatus::Publishing],
            drafts: [PostStatus::Draft],
            failed: [PostStatus::Failed],
        );

        return response()->json([
            'data' => $connections->map(fn (Connection $connection) => [
                'connection_id' => (string) $connection->id,
                'name' => $connection->name,
                'username' => $connection->credentials['username'] ?? null,
                'color' => $connection->color,
                // Only what an earlier lookup already paid for. The list never
                // waits on Meta: each card asks for its own profile afterwards
                // (GET /accounts/{connection}/profile).
                'profile' => Cache::get($this->profileKey($connection)),
            ] + $queue[(string) $connection->id])->values(),
        ]);
    }

    /**
     * The profile behind one card: followers, following, posts, picture.
     *
     * Asked by each card after the list has rendered, so one slow or broken
     * account never holds the others back. Cached per account, so a page of
     * cards costs at most one Graph call per account per hour. A token that
     * lost its permissions answers `instagram_permission_required`, which the
     * card turns into "needs to be reconnected".
     */
    public function profile(Request $request, string $id)
    {
        $connection = $this->instagramConnection($request, $id);

        $profile = Cache::remember(
            $this->profileKey($connection),
            now()->addMinutes(self::PROFILE_TTL_MINUTES),
            fn () => $this->clients->for($connection)->profile(),
        );

        return response()->json(['data' => $profile]);
    }

    /**
     * One account in full: its live profile and how much of the daily posting
     * allowance is left.
     *
     * The allowance is worth showing before someone composes rather than after
     * Meta refuses. It is 100 posts per rolling 24 hours, counted per Instagram
     * account — not per app — so it can be spent by whatever else the customer
     * posts with, and a number we did not fetch would be a number we invented.
     */
    public function show(Request $request, string $id)
    {
        $connection = $this->instagramConnection($request, $id);
        $client = $this->clients->for($connection);

        $profile = $client->profile();
        Cache::put($this->profileKey($connection), $profile, now()->addMinutes(self::PROFILE_TTL_MINUTES));

        $limit = $client->publishingLimit()['data'][0] ?? [];
        $quota = (int) ($limit['config']['quota_total'] ?? 100);
        $used = (int) ($limit['quota_usage'] ?? 0);

        return response()->json([
            'data' => [
                'connection_id' => (string) $connection->id,
                'name' => $connection->name,
                'color' => $connection->color,
                'profile' => $profile,
                'publishing_limit' => [
                    'quota' => $quota,
                    'used' => $used,
                    'remaining' => max(0, $quota - $used),
                ],
            ],
        ]);
    }

    private function profileKey(Connection $connection): string
    {
        return "instagram:profile:{$connection->id}";
    }
}
