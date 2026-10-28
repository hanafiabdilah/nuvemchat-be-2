<?php

namespace App\Http\Controllers;

use App\Events\YoutubeUpdated;
use App\Exceptions\YoutubeApiException;
use App\Models\YoutubeAccount;
use App\Services\Youtube\YoutubeClientFactory;
use App\Services\Youtube\YoutubeOAuth;
use App\Services\Youtube\YoutubePresenter;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Google sends the Connect popup back.
 *
 * Ends on the dashboard's /oauth/result page, which closes the popup; the
 * YouTube page that opened it hears about the new channel through the
 * `youtube-updated` broadcast. Same shape as the TikTok and Instagram
 * callbacks, with one difference that matters: which workspace the channel
 * lands in comes from a single-use state we stored, not from a decodable one.
 */
class YoutubeOAuthController extends Controller
{
    public function __construct(
        private readonly YoutubeOAuth $oauth,
        private readonly YoutubeClientFactory $clients,
    ) {}

    public function callback(Request $request)
    {
        if ($request->query('error')) {
            // access_denied = the person closed the consent screen. Not a
            // failure worth more than a sentence.
            return $this->result(false, $request->query('error') === 'access_denied'
                ? 'YouTube connection cancelled.'
                : 'Google did not authorize the connection.');
        }

        $origin = $this->oauth->consumeState($request->query('state'));
        $code = $request->query('code');

        if (! $origin || ! is_string($code) || $code === '') {
            return $this->result(false, 'This connection link has expired. Start again from the YouTube page.');
        }

        try {
            $tokens = $this->oauth->exchangeCode($code);

            // The consent screen lets people untick a scope. Without it the
            // channel would connect and then fail every comment action.
            if (! YoutubeOAuth::grantedAll($tokens['scope'])) {
                return $this->result(false, 'Allow every permission Google asks for, so posts and comments can be managed.');
            }

            $account = $this->store($origin, $tokens);
        } catch (\Throwable $e) {
            Log::error('YouTube OAuth callback failed', ['error' => $e->getMessage()]);

            if ($e instanceof YoutubeApiException && in_array($e->reason(), ['youtubeSignupRequired', 'channel_missing'], true)) {
                return $this->result(false, 'This Google account has no YouTube channel. Create one on YouTube and connect again.');
            }

            return $this->result(false, UpstreamError::messageFrom(UpstreamProvider::Youtube, $e, [
                'operation' => 'youtube.oauth_callback',
            ]));
        }

        broadcast(new YoutubeUpdated((int) $account->tenant_id, (int) $account->id));

        return $this->result(true, 'YouTube channel connected successfully!');
    }

    /**
     * Create the channel's row, or refresh it when this workspace had it
     * already (a reconnect after Google stopped honouring the old grant).
     */
    private function store(array $origin, array $tokens): YoutubeAccount
    {
        // A throwaway account just to read the channel with the new token.
        $probe = new YoutubeAccount([
            'tenant_id' => $origin['tenant_id'],
            'status' => YoutubeAccount::STATUS_ACTIVE,
            'credentials' => $tokens,
        ]);

        $channel = $this->clients->for($probe)->channel();

        if (empty($channel['id'])) {
            throw new YoutubeApiException('No YouTube channel on this Google account.', 'channel_missing', 422);
        }

        $existing = YoutubeAccount::where('tenant_id', $origin['tenant_id'])
            ->where('channel_id', $channel['id'])
            ->first();

        // Google only sends a refresh token on a fresh consent; keep the one we
        // hold rather than overwrite it with nothing.
        if (empty($tokens['refresh_token']) && $existing) {
            $tokens['refresh_token'] = $existing->credentials['refresh_token'] ?? null;
        }

        $attributes = [
            'title' => $channel['snippet']['title'] ?? $channel['id'],
            'handle' => $channel['snippet']['customUrl'] ?? null,
            'thumbnail_url' => YoutubePresenter::thumbnail($channel['snippet']['thumbnails'] ?? []),
            'uploads_playlist_id' => $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null,
            'credentials' => $tokens,
            'status' => YoutubeAccount::STATUS_ACTIVE,
            'last_error' => null,
            'connected_at' => now(),
            'connected_by' => $origin['user_id'],
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return YoutubeAccount::create($attributes + [
            'tenant_id' => $origin['tenant_id'],
            'channel_id' => $channel['id'],
        ]);
    }

    private function result(bool $success, string $message)
    {
        return redirect(config('app.frontend_url').'/oauth/result?'.http_build_query([
            'status' => $success ? 'success' : 'error',
            'message' => $message,
        ]));
    }
}
