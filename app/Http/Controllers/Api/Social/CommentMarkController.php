<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Api\Social\Concerns\ResolvesCommentAccount;
use App\Http\Controllers\Controller;
use App\Services\Social\CommentMarks;
use Illuminate\Http\Request;

/**
 * Read and starred marks for the three Comments sections, in one place: the
 * marks behave the same on every platform, and only how the account is found
 * differs (a Connection for Instagram and TikTok, a YoutubeAccount for YouTube).
 */
class CommentMarkController extends Controller
{
    use ResolvesCommentAccount;

    public function __construct(
        private readonly CommentMarks $marks,
    ) {}

    /** The account's id, once the caller is proven to moderate it. */
    private function account(Request $request, string $platform, string $accountId): int
    {
        return (int) $this->commentAccount($request, $platform, $accountId)->id;
    }

    public function update(Request $request, string $platform, string $accountId, string $commentId)
    {
        $account = $this->account($request, $platform, $accountId);

        $data = $request->validate([
            'read' => ['sometimes', 'boolean'],
            'starred' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->marks->set(
                (int) $request->user()->tenant_id,
                $platform,
                $account,
                $commentId,
                array_key_exists('read', $data) ? (bool) $data['read'] : null,
                array_key_exists('starred', $data) ? (bool) $data['starred'] : null,
                (int) $request->user()->id,
            ),
        ]);
    }

    /** "Mark all as read" for what the person is looking at. */
    public function markRead(Request $request, string $platform, string $accountId)
    {
        $account = $this->account($request, $platform, $accountId);

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['string', 'max:128'],
        ]);

        return response()->json([
            'data' => ['marked' => $this->marks->markRead((int) $request->user()->tenant_id, $platform, $account, $data['ids'], (int) $request->user()->id)],
        ]);
    }
}
