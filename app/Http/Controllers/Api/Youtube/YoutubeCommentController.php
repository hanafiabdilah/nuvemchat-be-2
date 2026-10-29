<?php

namespace App\Http\Controllers\Api\Youtube;

use App\Http\Controllers\Api\Youtube\Concerns\ResolvesYoutubeAccount;
use App\Http\Controllers\Controller;
use App\Services\Social\CommentMarks;
use App\Services\Youtube\YoutubeClientFactory;
use App\Services\Youtube\YoutubePresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comment moderation on a channel.
 *
 * Nothing is stored, as on Instagram: comments live at YouTube and change
 * without telling us. What differs is the moderation vocabulary, which is
 * YouTube's and not Instagram's:
 *
 *  - Someone else's comment cannot be deleted by the channel — only its author
 *    can. The channel *removes* it (moderation status `rejected`), which also
 *    hides its replies, and may ban the author from commenting again.
 *  - YouTube holds some comments back on its own (`heldForReview`,
 *    `likelySpam`); those wait for the channel to approve them, and are a list
 *    of their own here.
 *  - The channel's own comments are the ones it can delete.
 *
 * Every moderation call costs 50 units of the platform's shared daily quota,
 * which is why there is no bulk action that loops over comments.
 */
class YoutubeCommentController extends Controller
{
    use ResolvesYoutubeAccount;

    public const STATUSES = ['published', 'heldForReview', 'likelySpam'];

    public function __construct(
        private readonly YoutubeClientFactory $clients,
        private readonly CommentMarks $marks,
    ) {}

    /**
     * The channel's Comments section: the newest threads across all its
     * videos — or one video's — in one list.
     */
    public function inbox(Request $request, string $accountId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'video' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:200'],
            'after' => ['nullable', 'string', 'max:512'],
        ]);

        $client = $this->clients->for($account);
        $filter = ! empty($data['video'])
            ? ['videoId' => $data['video']]
            : ['allThreadsRelatedToChannelId' => $account->channel_id];

        $page = $client->commentThreads(
            $filter,
            $data['status'] ?? 'published',
            $data['after'] ?? null,
            50,
            $data['search'] ?? null,
        );

        $threads = array_map(
            fn (array $thread) => YoutubePresenter::thread($thread, $account->channel_id),
            $page['items'] ?? [],
        );

        // The videos the threads are on, in one call, so each comment can
        // show what it is about.
        $videoIds = array_values(array_unique(array_filter(array_column($threads, 'video_id'))));
        $videos = array_map(function (array $video) {
            $presented = YoutubePresenter::video($video);

            return [
                'id' => $presented['id'],
                'title' => $presented['title'],
                'thumbnail_url' => $presented['thumbnail_url'],
                'url' => $presented['url'],
                'comment_count' => $presented['comment_count'],
                'published_at' => $presented['published_at'],
            ];
        }, $client->videos($videoIds));

        return response()->json([
            'data' => $this->marks->apply($threads, (int) $account->tenant_id, 'youtube'),
            'videos' => $videos,
            'next_cursor' => $page['nextPageToken'] ?? null,
            'has_more' => isset($page['nextPageToken']),
        ]);
    }

    /** One video's comments, for the video modal. */
    public function index(Request $request, string $accountId, string $videoId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $page = $this->clients->for($account)->commentThreads(
            ['videoId' => $videoId],
            'published',
            $request->string('after')->toString() ?: null,
            50,
        );

        return response()->json([
            'data' => array_map(
                fn (array $thread) => YoutubePresenter::thread($thread, $account->channel_id),
                $page['items'] ?? [],
            ),
            'next_cursor' => $page['nextPageToken'] ?? null,
            'has_more' => isset($page['nextPageToken']),
        ]);
    }

    /** Every reply under a comment — a thread only carries the first few. */
    public function replies(Request $request, string $accountId, string $commentId)
    {
        $account = $this->youtubeAccount($request, $accountId);
        $page = $this->clients->for($account)->replies($commentId);

        $replies = array_map(
            fn (array $reply) => YoutubePresenter::comment($reply, $account->channel_id),
            $page['items'] ?? [],
        );

        usort($replies, fn (array $a, array $b) => strtotime($a['published_at'] ?? '') <=> strtotime($b['published_at'] ?? ''));

        return response()->json(['data' => $replies]);
    }

    /** Reply publicly, as the channel. */
    public function reply(Request $request, string $accountId, string $commentId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $created = $this->clients->for($account)->reply($commentId, $data['message']);

        // Answering a comment is dealing with it.
        $this->marks->touchRead((int) $account->tenant_id, 'youtube', (int) $account->id, $commentId, (int) $request->user()->id);

        return response()->json([
            'data' => YoutubePresenter::comment($created, $account->channel_id),
        ], 201);
    }

    /**
     * Approve, hold for review, or remove someone else's comment.
     *
     * `ban_author` only means anything with `rejected` — YouTube refuses it
     * otherwise — so it is dropped rather than sent.
     */
    public function moderate(Request $request, string $accountId, string $commentId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $data = $request->validate([
            'status' => ['required', Rule::in(['published', 'heldForReview', 'rejected'])],
            'ban_author' => ['sometimes', 'boolean'],
        ]);

        $this->clients->for($account)->setModerationStatus(
            [$commentId],
            $data['status'],
            (bool) ($data['ban_author'] ?? false),
        );

        $this->marks->touchRead((int) $account->tenant_id, 'youtube', (int) $account->id, $commentId, (int) $request->user()->id);

        return response()->json([
            'message' => match ($data['status']) {
                'published' => 'Comment approved.',
                'heldForReview' => 'Comment held for review.',
                default => 'Comment removed.',
            },
        ]);
    }

    /** Delete one of the channel's own comments. */
    public function destroy(Request $request, string $accountId, string $commentId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $this->clients->for($account)->deleteComment($commentId);

        return response()->json(['message' => 'Comment deleted.']);
    }
}
