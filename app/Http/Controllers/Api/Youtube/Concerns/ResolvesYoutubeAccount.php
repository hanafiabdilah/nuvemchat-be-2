<?php

namespace App\Http\Controllers\Api\Youtube\Concerns;

use App\Models\YoutubeAccount;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesYoutubeAccount
{
    /**
     * The channel this request is about, scoped to the caller's workspace.
     *
     * One gate, not Instagram's three: a channel is not a Connection, so there
     * is no per-connection access list to check — the YouTube permissions on
     * the route are the whole of who may act on it.
     */
    protected function youtubeAccount(Request $request, int|string $id): YoutubeAccount
    {
        $account = YoutubeAccount::where('tenant_id', $request->user()->tenant_id)->find($id);

        if (! $account) {
            throw new HttpException(404, 'YouTube channel not found.');
        }

        return $account;
    }
}
