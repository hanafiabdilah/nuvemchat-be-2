<?php

namespace App\Http\Controllers\Api\TikTok;

use App\Http\Controllers\Api\TikTok\Concerns\ResolvesTikTokConnection;
use App\Http\Controllers\Controller;
use App\Services\TikTok\TikTokAccountsClientFactory;
use App\Services\TikTok\TikTokPresenter;
use Illuminate\Http\Request;

/**
 * Comment moderation on a TikTok account's videos.
 *
 * Nothing is stored, as on Instagram. Two TikTok facts shape it:
 *
 *  - Comments are read per video — the API has no account-wide list — and
 *    each endpoint allows 40 requests a minute per account. So the Comments
 *    section reads one video at a time, chosen by the person, rather than
 *    fanning out a call per video.
 *  - Someone else's comment cannot be deleted, only hidden; the account's own
 *    comments are the ones it can delete.
 */
class TikTokCommentController extends Controller
{
    use ResolvesTikTokConnection;

    public function __construct(
        private readonly TikTokAccountsClientFactory $clients,
    ) {}

    public function index(Request $request, string $connectionId, string $videoId)
    {
        $client = $this->client($request, $connectionId);

        $page = $client->comments($videoId, $request->filled('cursor') ? (int) $request->input('cursor') : null);

        return response()->json([
            'data' => array_map(fn (array $comment) => TikTokPresenter::comment($comment), $page['comments'] ?? []),
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

        return response()->json([
            'data' => TikTokPresenter::reply($client->reply($videoId, $commentId, $data['text']) + ['owner' => true]),
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
