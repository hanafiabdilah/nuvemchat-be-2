<?php

namespace App\Http\Controllers\Api\Social;

use App\Enums\Connection\Channel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\YoutubeAccount;
use App\Services\Social\CommentMarks;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Read and starred marks for the three Comments sections, in one place: the
 * marks behave the same on every platform, and only how the account is found
 * differs (a Connection for Instagram and TikTok, a YoutubeAccount for YouTube).
 */
class CommentMarkController extends Controller
{
    public function __construct(private readonly CommentMarks $marks) {}

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

    /** The account's id, once the caller is proven to moderate it. */
    private function account(Request $request, string $platform, string $accountId): int
    {
        $user = $request->user();

        if (! in_array($platform, CommentMarks::PLATFORMS, true)) {
            throw new HttpException(404, 'Unknown platform.');
        }

        if (! $user->can("{$platform}-comments.manage")) {
            throw new HttpException(403, 'You may not moderate these comments.');
        }

        if ($platform === 'youtube') {
            $account = YoutubeAccount::where('tenant_id', $user->tenant_id)->find($accountId);

            if (! $account) {
                throw new HttpException(404, 'YouTube channel not found.');
            }

            return (int) $account->id;
        }

        $connection = Connection::where('tenant_id', $user->tenant_id)
            ->where('channel', $platform === 'instagram' ? Channel::Instagram : Channel::TikTok)
            ->find($accountId);

        if (! $connection) {
            throw new HttpException(404, 'Account not found.');
        }

        if (! $user->canAccessConnection($connection)) {
            throw new HttpException(403, 'You do not have access to this account.');
        }

        return (int) $connection->id;
    }
}
