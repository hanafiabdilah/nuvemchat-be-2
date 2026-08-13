<?php

namespace App\Services\Instagram;

use App\Exceptions\InstagramApiException;
use App\Models\Connection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper over the Instagram Graph API for one connection.
 *
 * This is the *Instagram Login* flavour (`graph.instagram.com`), which is what
 * every connection in this product uses. Two absences are properties of that
 * choice rather than gaps here, and neither has a workaround on this host:
 *
 *   - **No media delete.** `DELETE /{ig-media-id}` exists, but only on the
 *     Facebook Login flavour, and it needs `instagram_manage_contents` — a
 *     permission with no `instagram_business_*` counterpart.
 *   - **No caption edit.** `POST /{ig-media-id}` accepts exactly one parameter,
 *     `comment_enabled`. Meta offers no way to change a caption after the fact.
 *
 * Callers that want to offer either must say so in the UI, not retry here.
 */
class InstagramGraphClient
{
    public const GRAPH_VERSION = 'v25.0';

    private const BASE_URL = 'https://graph.instagram.com';

    /** Fields the grid needs for a tile, and the modal for its header. */
    public const MEDIA_FIELDS = 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp,username,like_count,comments_count,is_comment_enabled,children{id,media_type,media_url,thumbnail_url}';

    public const COMMENT_FIELDS = 'id,text,timestamp,username,like_count,hidden,replies{id,text,timestamp,username,like_count,hidden}';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * The Instagram account id this connection posts as.
     *
     * Stored at connect time from `GET /me`, so it is already the id the
     * publishing endpoints expect — no extra lookup on the send path.
     */
    public function accountId(): string
    {
        $id = $this->connection->credentials['instagram_account_id'] ?? null;

        if (! $id) {
            throw new InstagramApiException('This Instagram connection has no account id. Reconnect the account.', httpStatus: 422);
        }

        return (string) $id;
    }

    private function token(): string
    {
        $token = $this->connection->credentials['access_token'] ?? null;

        if (! $token) {
            throw new InstagramApiException('This Instagram connection has no access token. Reconnect the account.', httpStatus: 422);
        }

        return (string) $token;
    }

    // ---------------------------------------------------------------- reading

    /**
     * The account behind this connection, as Instagram currently describes it.
     *
     * `followers_count` is requested optimistically and dropped on refusal:
     * it is not worth failing the whole account card over a counter, and which
     * fields a given account exposes has moved around between API versions.
     */
    public function profile(): array
    {
        try {
            return $this->get('me', [
                'fields' => 'id,username,name,profile_picture_url,media_count,followers_count',
            ]);
        } catch (InstagramApiException $e) {
            if ($e->isPermissionError()) {
                throw $e;
            }

            return $this->get('me', ['fields' => 'id,username,name,profile_picture_url,media_count']);
        }
    }

    /** A page of the account's published media, newest first. */
    public function media(int $limit = 24, ?string $after = null): array
    {
        return $this->get($this->accountId() . '/media', array_filter([
            'fields' => self::MEDIA_FIELDS,
            'limit' => $limit,
            'after' => $after,
        ]));
    }

    public function mediaDetail(string $mediaId): array
    {
        return $this->get($mediaId, ['fields' => self::MEDIA_FIELDS]);
    }

    /**
     * How much of the 100-posts-per-rolling-24h allowance is spent.
     *
     * Worth showing before the user composes rather than after Meta refuses:
     * the quota is per account and shared with every other tool the customer
     * posts from, so it can be exhausted by something outside this product.
     */
    public function publishingLimit(): array
    {
        return $this->get($this->accountId() . '/content_publishing_limit', [
            'fields' => 'config,quota_usage',
        ]);
    }

    // ------------------------------------------------------------- publishing

    /** Step one: hand Meta the media and get a container back. */
    public function createContainer(array $params): array
    {
        return $this->post($this->accountId() . '/media', $params);
    }

    /**
     * Step two (async types only): has Meta finished fetching and transcoding?
     *
     * `status_code` is one of IN_PROGRESS, FINISHED, ERROR, EXPIRED, PUBLISHED.
     * `status` carries the human explanation when it is ERROR.
     */
    public function containerStatus(string $containerId): array
    {
        return $this->get($containerId, ['fields' => 'status_code,status']);
    }

    /** Step three: make it live. */
    public function publishContainer(string $containerId): array
    {
        return $this->post($this->accountId() . '/media_publish', [
            'creation_id' => $containerId,
        ]);
    }

    // --------------------------------------------------------------- comments

    public function comments(string $mediaId, int $limit = 50, ?string $after = null): array
    {
        return $this->get($mediaId . '/comments', array_filter([
            'fields' => self::COMMENT_FIELDS,
            'limit' => $limit,
            'after' => $after,
        ]));
    }

    public function replyToComment(string $commentId, string $message): array
    {
        return $this->post($commentId . '/replies', ['message' => $message]);
    }

    /**
     * Hide or unhide a comment.
     *
     * Hiding is the moderation tool worth reaching for first: the comment stays
     * visible to whoever wrote it, so the author is not provoked into posting
     * it again, but nobody else sees it.
     */
    public function setCommentHidden(string $commentId, bool $hidden): array
    {
        return $this->post($commentId, ['hide' => $hidden ? 'true' : 'false']);
    }

    public function deleteComment(string $commentId): array
    {
        return $this->request('delete', $commentId, []);
    }

    /** The one property of a live post Meta lets us change. */
    public function setCommentsEnabled(string $mediaId, bool $enabled): array
    {
        return $this->post($mediaId, ['comment_enabled' => $enabled ? 'true' : 'false']);
    }

    // ------------------------------------------------------------------- http

    private function get(string $path, array $params = []): array
    {
        return $this->request('get', $path, $params);
    }

    private function post(string $path, array $params = []): array
    {
        return $this->request('post', $path, $params);
    }

    private function request(string $method, string $path, array $params): array
    {
        $response = Http::timeout(30)
            ->asForm()
            ->{$method}(
                self::BASE_URL . '/' . self::GRAPH_VERSION . '/' . ltrim($path, '/'),
                $params + ['access_token' => $this->token()],
            );

        return $this->unwrap($response);
    }

    private function unwrap(Response $response): array
    {
        $body = $response->json() ?? [];

        if ($response->failed() || isset($body['error'])) {
            throw InstagramApiException::fromResponse($body, $response->status());
        }

        return $body;
    }
}
