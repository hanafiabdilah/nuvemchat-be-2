<?php

namespace App\Services\Youtube;

use App\Exceptions\YoutubeApiException;
use App\Models\YoutubeAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The YouTube Data API v3 for one connected channel.
 *
 * Every call is billed against ONE Google Cloud project — the platform's — and
 * that budget is shared by every workspace: 10,000 units a day by default, plus
 * a separate bucket of 100 uploads a day. The method choices below follow from
 * it. The feed is read through the uploads playlist (1 unit) and never through
 * search.list (its own 100-call bucket); comment moderation costs 50 units a
 * call, so nothing here moderates in a loop.
 */
class YoutubeClient
{
    public const API = 'https://www.googleapis.com/youtube/v3';
    public const UPLOAD_API = 'https://www.googleapis.com/upload/youtube/v3';

    /** Parts the grid, the detail modal and the edit form need, in one read. */
    private const VIDEO_PARTS = 'snippet,status,statistics,contentDetails';

    public function __construct(
        private readonly YoutubeAccount $account,
        private readonly YoutubeOAuth $oauth,
    ) {}

    public function account(): YoutubeAccount
    {
        return $this->account;
    }

    // ------------------------------------------------------------- the token

    /**
     * A valid access token, refreshed when it is about to expire.
     *
     * Refreshing is serialised per channel: two requests arriving together
     * would otherwise both refresh, and while Google tolerates that, the slower
     * one would write an older token over the newer one.
     */
    public function accessToken(bool $force = false): string
    {
        if ($this->account->needsReconnect()) {
            throw new YoutubeApiException('This YouTube channel must be connected again.', 'reconnect_required', 422);
        }

        $credentials = $this->account->credentials ?? [];
        $expiresAt = isset($credentials['token_expires_at']) ? Carbon::parse($credentials['token_expires_at']) : null;

        if (! $force && ! empty($credentials['access_token']) && $expiresAt && $expiresAt->isAfter(now()->addSeconds(60))) {
            return (string) $credentials['access_token'];
        }

        return Cache::lock("youtube:token:{$this->account->id}", 15)->block(10, function () use ($force) {
            $this->account->refresh();
            $credentials = $this->account->credentials ?? [];
            $expiresAt = isset($credentials['token_expires_at']) ? Carbon::parse($credentials['token_expires_at']) : null;

            // Someone else refreshed while we waited.
            if (! $force && ! empty($credentials['access_token']) && $expiresAt && $expiresAt->isAfter(now()->addSeconds(60))) {
                return (string) $credentials['access_token'];
            }

            if (empty($credentials['refresh_token'])) {
                $this->markReconnect('No refresh token stored.');

                throw new YoutubeApiException('This YouTube channel must be connected again.', 'reconnect_required', 422);
            }

            try {
                $fresh = $this->oauth->refresh((string) $credentials['refresh_token']);
            } catch (YoutubeApiException $e) {
                if ($e->reason() === 'invalid_grant') {
                    $this->markReconnect($e->getMessage());
                }

                throw $e;
            }

            $this->account->update(['credentials' => array_merge($credentials, $fresh)]);

            return $fresh['access_token'];
        });
    }

    private function markReconnect(string $why): void
    {
        $this->account->update([
            'status' => YoutubeAccount::STATUS_RECONNECT,
            'last_error' => $why,
        ]);
    }

    // --------------------------------------------------------------- channel

    /** The authorised channel, as YouTube describes it now. */
    public function channel(): array
    {
        return $this->get('channels', [
            'part' => 'snippet,statistics,contentDetails',
            'mine' => 'true',
        ])['items'][0] ?? [];
    }

    /** Categories a video may be filed under in this region. */
    public function categories(string $regionCode, string $hl): array
    {
        return $this->get('videoCategories', [
            'part' => 'snippet',
            'regionCode' => $regionCode,
            'hl' => $hl,
        ])['items'] ?? [];
    }

    // ---------------------------------------------------------------- videos

    /**
     * A page of the channel's uploads, newest first, with full detail.
     *
     * Two calls (2 units): the uploads playlist gives the ids in order —
     * including private and scheduled videos, which the owner can see — and
     * videos.list gives everything else for those ids in one go.
     *
     * @return array{items: list<array>, next_page_token: ?string}
     */
    public function uploads(string $playlistId, int $limit = 24, ?string $pageToken = null): array
    {
        $page = $this->get('playlistItems', array_filter([
            'part' => 'contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => max(1, min(50, $limit)),
            'pageToken' => $pageToken,
        ]));

        $ids = array_values(array_filter(array_map(
            fn (array $item) => $item['contentDetails']['videoId'] ?? null,
            $page['items'] ?? [],
        )));

        $videos = $this->videos($ids);
        $byId = collect($videos)->keyBy('id');

        return [
            // In the playlist's order: videos.list returns them in whatever
            // order it likes.
            'items' => collect($ids)->map(fn (string $id) => $byId->get($id))->filter()->values()->all(),
            'next_page_token' => $page['nextPageToken'] ?? null,
        ];
    }

    /** @param  list<string>  $ids */
    public function videos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->get('videos', [
            'part' => self::VIDEO_PARTS,
            'id' => implode(',', array_slice($ids, 0, 50)),
            'maxResults' => 50,
        ])['items'] ?? [];
    }

    public function video(string $id): ?array
    {
        return $this->videos([$id])[0] ?? null;
    }

    /**
     * Change a live video's metadata.
     *
     * videos.update replaces every field of a part it is sent — a snippet
     * without `categoryId` is refused, and a status without `privacyStatus`
     * resets it — so callers merge their changes into what YouTube returned,
     * never send a partial part.
     */
    public function updateVideo(string $id, array $snippet, array $status): array
    {
        return $this->send('put', 'videos', ['part' => 'snippet,status'], [
            'id' => $id,
            'snippet' => $snippet,
            'status' => $status,
        ]);
    }

    /** Remove a video from YouTube for good. */
    public function deleteVideo(string $id): void
    {
        $this->send('delete', 'videos', ['id' => $id]);
    }

    // -------------------------------------------------------------- comments

    /**
     * A page of comment threads.
     *
     * @param  array{videoId?: string, allThreadsRelatedToChannelId?: string}  $filter  exactly one
     */
    public function commentThreads(array $filter, string $moderationStatus = 'published', ?string $pageToken = null, int $limit = 50, ?string $search = null): array
    {
        return $this->get('commentThreads', array_filter([
            'part' => 'snippet,replies',
            'moderationStatus' => $moderationStatus,
            'order' => 'time',
            'textFormat' => 'plainText',
            'maxResults' => max(1, min(100, $limit)),
            'pageToken' => $pageToken,
            'searchTerms' => $search,
        ] + $filter));
    }

    /** Every reply under one comment (a thread only carries the first few). */
    public function replies(string $parentId, ?string $pageToken = null): array
    {
        return $this->get('comments', array_filter([
            'part' => 'snippet',
            'parentId' => $parentId,
            'maxResults' => 100,
            'textFormat' => 'plainText',
            'pageToken' => $pageToken,
        ]));
    }

    public function reply(string $parentId, string $text): array
    {
        return $this->send('post', 'comments', ['part' => 'snippet'], [
            'snippet' => ['parentId' => $parentId, 'textOriginal' => $text],
        ]);
    }

    /**
     * Approve, hold or remove other people's comments.
     *
     * `rejected` is how a channel removes a comment it did not write — only the
     * author can delete one — and it hides the replies with it. `banAuthor`
     * additionally rejects that person's future comments on the channel.
     *
     * @param  list<string>  $ids
     */
    public function setModerationStatus(array $ids, string $status, bool $banAuthor = false): void
    {
        $this->send('post', 'comments/setModerationStatus', array_filter([
            'id' => implode(',', $ids),
            'moderationStatus' => $status,
            'banAuthor' => $banAuthor && $status === 'rejected' ? 'true' : null,
        ]));
    }

    /** Delete a comment. YouTube allows it only for comments the channel wrote. */
    public function deleteComment(string $id): void
    {
        $this->send('delete', 'comments', ['id' => $id]);
    }

    // ---------------------------------------------------------------- upload

    /**
     * Open a resumable upload session; returns the session URI.
     *
     * Nothing is created on YouTube until the last byte arrives, so an abandoned
     * session costs nothing — and reusing a live session is what keeps a
     * retried upload from becoming a second video.
     */
    public function startUpload(array $metadata, int $size, string $mime, bool $notifySubscribers): string
    {
        $response = $this->authed()
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $size,
                'X-Upload-Content-Type' => $mime,
            ])
            ->withoutRedirecting()
            ->post(self::UPLOAD_API.'/videos?'.http_build_query([
                'uploadType' => 'resumable',
                'part' => 'snippet,status',
                'notifySubscribers' => $notifySubscribers ? 'true' : 'false',
            ]), $metadata);

        if ($response->failed()) {
            throw YoutubeApiException::fromResponse($response->json() ?? [], $response->status());
        }

        $location = $response->header('Location');

        if ($location === '') {
            throw new YoutubeApiException('YouTube did not open an upload session.');
        }

        return $location;
    }

    /**
     * Send one chunk.
     *
     * @return array{done: bool, next: int, video: ?array}
     *                                                     `done` with the created video when the last byte landed, otherwise the
     *                                                     offset YouTube confirmed it holds.
     */
    public function putChunk(string $sessionUri, string $bytes, int $start, int $total, string $mime): array
    {
        $end = $start + strlen($bytes) - 1;

        $response = $this->authed(timeout: 120)
            ->withoutRedirecting()
            ->withHeaders(['Content-Range' => "bytes {$start}-{$end}/{$total}"])
            ->withBody($bytes, $mime)
            ->put($sessionUri);

        return $this->readUploadResponse($response);
    }

    /** Where an interrupted session stands: the offset to resume from. */
    public function uploadStatus(string $sessionUri, int $total): array
    {
        $response = $this->authed()
            ->withoutRedirecting()
            ->withHeaders(['Content-Range' => "bytes */{$total}"])
            ->withBody('', 'application/octet-stream')
            ->put($sessionUri);

        return $this->readUploadResponse($response);
    }

    /** 308 = keep going (with the confirmed range); 200/201 = the video exists. */
    private function readUploadResponse(Response $response): array
    {
        if ($response->status() === 308) {
            $range = $response->header('Range');
            $next = preg_match('/bytes=0-(\d+)/', $range, $m) ? ((int) $m[1]) + 1 : 0;

            return ['done' => false, 'next' => $next, 'video' => null];
        }

        if ($response->successful()) {
            return ['done' => true, 'next' => 0, 'video' => $response->json() ?? []];
        }

        // A session lives about a week; past that YouTube forgets it. Named
        // apart so the uploader can open a fresh one instead of failing.
        if (in_array($response->status(), [404, 410], true)) {
            throw new YoutubeApiException('The upload session expired.', 'upload_session_expired', 422);
        }

        throw YoutubeApiException::fromResponse($response->json() ?? [], $response->status());
    }

    // ------------------------------------------------------------------ http

    private function authed(int $timeout = 30): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout($timeout);
    }

    private function get(string $path, array $query): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * One API call, with a single retry on 401: an access token can be
     * revoked or expire between the expiry check and the request.
     */
    private function send(string $method, string $path, array $query = [], ?array $body = null, bool $retried = false): array
    {
        $url = self::API.'/'.$path.($query ? '?'.http_build_query($query) : '');
        $request = Http::withToken($this->accessToken($retried))->acceptJson()->timeout(30);

        $response = match ($method) {
            'get' => $request->get($url),
            'delete' => $request->delete($url),
            default => $body === null
                ? $request->withBody('', 'application/json')->{$method}($url)
                : $request->{$method}($url, $body),
        };

        if ($response->status() === 401 && ! $retried) {
            return $this->send($method, $path, $query, $body, true);
        }

        if ($response->failed()) {
            throw YoutubeApiException::fromResponse($response->json() ?? [], $response->status());
        }

        return $response->json() ?? [];
    }
}
