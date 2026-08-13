<?php

namespace App\Http\Controllers\Api\Instagram;

use App\Http\Controllers\Api\Instagram\Concerns\ResolvesInstagramConnection;
use App\Http\Controllers\Controller;
use App\Services\Instagram\InstagramGraphClientFactory;
use Illuminate\Http\Request;

/**
 * Comment moderation on a published post.
 *
 * Nothing here is stored: comments live at Instagram, they change without
 * telling us, and a mirror of them would be a mirror that is wrong. Every
 * action is a pass-through to the Graph API using the connection's token.
 *
 * The three tools, in the order they are usually wanted: reply (the public
 * answer), hide (removes it from everyone but its author, so nobody is
 * provoked into reposting), and delete (final, and visible as an absence).
 */
class InstagramCommentController extends Controller
{
    use ResolvesInstagramConnection;

    public function __construct(
        private readonly InstagramGraphClientFactory $clients,
    ) {}

    public function index(Request $request, string $connectionId, string $mediaId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $comments = $this->clients->for($connection)->comments(
            $mediaId,
            (int) $request->integer('limit', 50),
            $request->string('after')->toString() ?: null,
        );

        return response()->json([
            'data' => $comments['data'] ?? [],
            'next_cursor' => $comments['paging']['cursors']['after'] ?? null,
            'has_more' => isset($comments['paging']['next']),
        ]);
    }

    /** Reply publicly, under the comment. */
    public function reply(Request $request, string $connectionId, string $commentId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2200'],
        ]);

        return response()->json([
            'data' => $this->clients->for($connection)->replyToComment($commentId, $data['message']),
        ], 201);
    }

    /** Hide or unhide. */
    public function update(Request $request, string $connectionId, string $commentId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $data = $request->validate([
            'hidden' => ['required', 'boolean'],
        ]);

        $this->clients->for($connection)->setCommentHidden($commentId, $data['hidden']);

        return response()->json(['message' => $data['hidden'] ? 'Comment hidden.' : 'Comment unhidden.']);
    }

    public function destroy(Request $request, string $connectionId, string $commentId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $this->clients->for($connection)->deleteComment($commentId);

        return response()->json(['message' => 'Comment deleted.']);
    }

    /**
     * Turn commenting on a post on or off.
     *
     * This is the only property of a live post the Instagram Login API will
     * change — there is no caption edit and no delete — so it is the whole of
     * what "manage a published post" can mean here.
     */
    public function setCommentsEnabled(Request $request, string $connectionId, string $mediaId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->clients->for($connection)->setCommentsEnabled($mediaId, $data['enabled']);

        return response()->json([
            'message' => $data['enabled'] ? 'Comments enabled.' : 'Comments disabled.',
        ]);
    }
}
