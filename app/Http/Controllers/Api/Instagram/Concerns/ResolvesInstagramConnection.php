<?php

namespace App\Http\Controllers\Api\Instagram\Concerns;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesInstagramConnection
{
    /**
     * The Instagram connection this request is about, or a refusal.
     *
     * Three gates, in this order: it belongs to the caller's tenant, it is
     * actually an Instagram connection, and the caller has been given access to
     * it. The last one is the one that is easy to forget — the permission
     * middleware on the route only says the user may manage Instagram posts in
     * general, not that they may manage *this* account's, and an agent whose
     * connection_user rows do not include it must not be able to post as it.
     */
    protected function instagramConnection(Request $request, int|string $id): Connection
    {
        $user = $request->user();

        $connection = Connection::where('tenant_id', $user->tenant_id)
            ->where('channel', Channel::Instagram)
            ->find($id);

        if (! $connection) {
            throw new HttpException(404, 'Instagram account not found.');
        }

        if (! $user->canAccessConnection($connection)) {
            throw new HttpException(403, 'You do not have access to this Instagram account.');
        }

        return $connection;
    }
}
