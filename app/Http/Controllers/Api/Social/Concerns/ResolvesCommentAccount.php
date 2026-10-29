<?php

namespace App\Http\Controllers\Api\Social\Concerns;

use App\Enums\Billing\Feature;
use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Models\YoutubeAccount;
use App\Services\Billing\SubscriptionGate;
use App\Services\Social\CommentMarks;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The account behind a comment route that serves all three networks: a
 * Connection for Instagram and TikTok, a YoutubeAccount for YouTube — found
 * only once the caller is proven to moderate its comments.
 *
 * The network is a route parameter on these routes, so the moderation
 * permission and the network's plan feature are checked here: the middleware
 * on each network's own route group does not reach them.
 */
trait ResolvesCommentAccount
{
    protected function commentAccount(Request $request, string $platform, string $accountId): Connection|YoutubeAccount
    {
        $user = $request->user();

        if (! in_array($platform, CommentMarks::PLATFORMS, true)) {
            throw new HttpException(404, 'Unknown platform.');
        }

        if (! $user->can("{$platform}-comments.manage")) {
            throw new HttpException(403, 'You may not moderate these comments.');
        }

        $this->assertCommentFeature($user->tenant, $platform);

        if ($platform === 'youtube') {
            $account = YoutubeAccount::where('tenant_id', $user->tenant_id)->find($accountId);

            if (! $account) {
                throw new HttpException(404, 'YouTube channel not found.');
            }

            return $account;
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

        return $connection;
    }

    /** The network's plan feature, refused the way `feature:` refuses it. */
    private function assertCommentFeature($tenant, string $platform): void
    {
        if (! config('services.billing.enforce') || $tenant === null) {
            return;
        }

        $feature = match ($platform) {
            'instagram' => Feature::InstagramManagement,
            'tiktok' => Feature::TiktokManagement,
            'youtube' => Feature::YoutubeManagement,
        };

        if (app(SubscriptionGate::class)->feature($tenant, $feature->value)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => "This feature ({$feature->value}) is not included in your current plan.",
            'code' => 'feature_not_in_plan',
            'feature' => $feature->value,
        ], 403));
    }
}
