<?php

namespace App\Http\Controllers\Api\TikTok;

use App\Http\Controllers\Api\TikTok\Concerns\ResolvesTikTokConnection;
use App\Http\Controllers\Controller;
use App\Services\Social\CommentMarks;
use App\Services\TikTok\TikTokAccountsClientFactory;
use App\Services\TikTok\TikTokPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Comment moderation on a TikTok account's videos.
 *
 * Nothing is stored, as on Instagram. Two TikTok facts shape it:
 *
 *  - Comments are read per video — the API has no account-wide list — and
 *    each endpoint allows 40 requests a minute per account. So a post's
 *    comments are read when the person opens it, and the all-posts view is a
 *    bounded, cached fan-out over the latest posts (see inbox()).
 *  - Someone else's comment cannot be deleted, only hidden; the account's own
 *    comments are the ones it can delete.
 */
class TikTokCommentController extends Controller
{
    use ResolvesTikTokConnection;

    /** Posts whose comments the all-posts view reads (one call each). */
    public const INBOX_POSTS = 8;

    /** Comments read per post in that view. */
    public const INBOX_COMMENTS_PER_POST = 20;

    public function __construct(
        private readonly TikTokAccountsClientFactory $clients,
        private readonly CommentMarks $marks,
    ) {}

    /**
     * Every recent comment across the account's latest posts, newest first —
     * the all-posts view.
     *
     * TikTok has no account-wide list, so this is one call per post; it is
     * bounded to the latest posts that have comments and held for half a
     * minute, because the endpoint allows 40 calls a minute per account and a
     * moderator pressing Refresh must not spend them all. Marks are applied
     * after the cache, so a comment marked read stays read on the next look.
     */
    public function inbox(Request $request, string $connectionId)
    {
        $connection = $this->tiktokConnection($request, $connectionId);
        $client = $this->clients->for($connection);
        $client->assertPostPermissions();

        $page = Cache::remember("tiktok:comment-inbox:{$connection->id}", now()->addSeconds(30), function () use ($client) {
            $videos = array_map(fn (array $video) => TikTokPresenter::video($video), $client->videos(null, 20)['videos'] ?? []);
            $withComments = array_slice(array_values(array_filter($videos, fn ($video) => $video['comment_count'] > 0)), 0, self::INBOX_POSTS);

            $comments = [];
            foreach ($withComments as $video) {
                $rows = $client->comments($video['id'], null, self::INBOX_COMMENTS_PER_POST)['comments'] ?? [];
                foreach ($rows as $row) {
                    $comments[] = TikTokPresenter::comment($row + ['video_id' => $video['id']]);
                }
            }

            usort($comments, fn (array $a, array $b) => strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? ''));

            return ['comments' => $comments, 'posts' => $withComments];
        });

        return response()->json([
            'data' => $this->marks->apply($page['comments'], (int) $connection->tenant_id, 'tiktok'),
            'posts' => $page['posts'],
            'window' => ['posts' => self::INBOX_POSTS, 'comments_per_post' => self::INBOX_COMMENTS_PER_POST],
        ]);
    }

    public function index(Request $request, string $connectionId, string $videoId)
    {
        $connection = $this->tiktokConnection($request, $connectionId);
        $client = $this->clients->for($connection);
        $client->assertPostPermissions();

        $page = $client->comments($videoId, $request->filled('cursor') ? (int) $request->input('cursor') : null);
        $comments = array_map(fn (array $comment) => TikTokPresenter::comment($comment + ['video_id' => $videoId]), $page['comments'] ?? []);

        return response()->json([
            'data' => $this->marks->apply($comments, (int) $connection->tenant_id, 'tiktok'),
            'next_cursor' => isset($page['cursor']) ? (string) $page['cursor'] : null,
            'has_more' => (bool) ($page['has_more'] ?? false),
        ]);
    }

    public function replies(Request $request, string $connectionId, string $videoId, string $commentId)
    {
        $client = $this->client($request, $connectionId);
        $replies = [];
        $cursor = null;

        // A thread is short; read it whole (bounded) so the reader sees the
        // conversation, not its first page.
        for ($page = 0; $page < 5; $page++) {
            $result = $client->replies($videoId, $commentId, $cursor);
            $replies = [...$replies, ...array_map(fn (array $reply) => TikTokPresenter::reply($reply), $result['comments'] ?? [])];

            if (! ($result['has_more'] ?? false)) {
                break;
            }

            $cursor = (int) ($result['cursor'] ?? 0);
        }

        return response()->json(['data' => $replies]);
    }

    /** A new top-level comment, as the account. */
    public function store(Request $request, string $connectionId, string $videoId)
    {
        $client = $this->client($request, $connectionId);
        $data = $request->validate(['text' => ['required', 'string', 'max:1200']]);

        return response()->json([
            'data' => TikTokPresenter::reply($client->createComment($videoId, $data['text']) + ['owner' => true]),
        ], 201);
    }

    public function reply(Request $request, string $connectionId, string $videoId, string $commentId)
    {
        $client = $this->client($request, $connectionId);
        $data = $request->validate(['text' => ['required', 'string', 'max:1200']]);
        $reply = $client->reply($videoId, $commentId, $data['text']);

        // Answering a comment is dealing with it.
        $this->marks->touchRead((int) $request->user()->tenant_id, 'tiktok', (int) $connectionId, $commentId, (int) $request->user()->id);

        return response()->json([
            'data' => TikTokPresenter::reply($reply + ['owner' => true]),
        ], 201);
    }

    /** Like/unlike and hide/unhide — the two switches a comment has. */
    public function update(Request $request, string $connectionId, string $videoId, string $commentId)
    {
        $client = $this->client($request, $connectionId);

        $data = $request->validate([
            'liked' => ['sometimes', 'boolean'],
            'hidden' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('liked', $data)) {
            $client->like($commentId, (bool) $data['liked']);
        }

        if (array_key_exists('hidden', $data)) {
            $client->hide($videoId, $commentId, (bool) $data['hidden']);

            if ($data['hidden']) {
                $this->marks->touchRead((int) $request->user()->tenant_id, 'tiktok', (int) $connectionId, $commentId, (int) $request->user()->id);
            }
        }

        return response()->json(['message' => 'Comment updated.']);
    }

    /** Delete one of the account's own comments. */
    public function destroy(Request $request, string $connectionId, string $commentId)
    {
        $this->client($request, $connectionId)->delete($commentId);

        return response()->json(['message' => 'Comment deleted.']);
    }

    private function client(Request $request, string $connectionId)
    {
        $client = $this->clients->for($this->tiktokConnection($request, $connectionId));
        $client->assertPostPermissions();

        return $client;
    }
}
