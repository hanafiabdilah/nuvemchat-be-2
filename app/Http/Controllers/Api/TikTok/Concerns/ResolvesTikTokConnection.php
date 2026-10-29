<?php

namespace App\Http\Controllers\Api\TikTok\Concerns;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesTikTokConnection
{
    /**
     * The TikTok connection this request is about, or a refusal — the same
     * three gates as Instagram's: the caller's workspace, the right channel,
     * and the caller's own access to that connection (connection_user), which
     * the route permission alone does not say.
     */
    protected function tiktokConnection(Request $request, int|string $id): Connection
    {
        $user = $request->user();

        $connection = Connection::where('tenant_id', $user->tenant_id)
            ->where('channel', Channel::TikTok)
            ->find($id);

        if (! $connection) {
            throw new HttpException(404, 'TikTok account not found.');
        }

        if (! $user->canAccessConnection($connection)) {
            throw new HttpException(403, 'You do not have access to this TikTok account.');
        }

        return $connection;
    }
}
