<?php

namespace App\Services\TikTok;

use App\Exceptions\TikTokApiException;
use App\Models\Connection;
use App\Services\Connection\Channels\TikTokChannel;
use App\Services\Connection\TikTok\TikTokAuthClient;
use Illuminate\Support\Facades\Http;

/**
 * TikTok's Accounts API for one connected account: its posts, publishing to
 * it, and the comments on its videos.
 *
 * The same host and the same token as the inbox (TikTokMessagingClient) —
 * the difference is the permissions the token carries, which is why every
 * call checks them first: an account connected for the inbox before posting
 * existed holds a token without them and can only be fixed by connecting
 * again.
 *
 * Budget to keep in mind: 40 requests per minute per account *per endpoint*,
 * and 6 published videos per minute / 15 per day per account. Nothing here
 * loops over videos making calls.
 */
class TikTokAccountsClient
{
    /** The fields the grid and the post modal read, in one call. */
    public const VIDEO_FIELDS = [
        'item_id', 'media_type', 'thumbnail_url', 'share_url', 'embed_url', 'caption',
        'video_duration', 'likes', 'comments', 'shares', 'video_views', 'create_time',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function businessId(): string
    {
        return (string) ($this->connection->credentials['business_id'] ?? '');
    }

    /** Whether the stored grant includes posting and comments. */
    public function hasPostPermissions(): bool
    {
        $granted = array_filter(array_map('trim', explode(',', (string) ($this->connection->credentials['scope'] ?? ''))));

        return array_diff(TikTokAuthClient::POST_SCOPES, $granted) === [];
    }

    public function assertPostPermissions(): void
    {
        if (! $this->hasPostPermissions()) {
            throw new TikTokApiException('This TikTok account was connected without the post and comment permissions.', 'reconnect_required', 422);
        }
    }

    // ---------------------------------------------------------------- reading

    public function profile(): array
    {
        return $this->get('/business/get/', [
            'fields' => json_encode(['username', 'display_name', 'profile_image', 'followers_count', 'videos_count', 'total_likes', 'is_verified']),
        ]);
    }

    /** A page of the account's public posts, newest first. */
    public function videos(?int $cursor = null, int $max = 20): array
    {
        return $this->get('/business/video/list/', array_filter([
            'fields' => json_encode(self::VIDEO_FIELDS),
            'max_count' => max(1, min(20, $max)),
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));
    }

    /** @param  list<string>  $ids */
    public function videosById(array $ids): array
    {
        return $this->get('/business/video/list/', [
            'fields' => json_encode(self::VIDEO_FIELDS),
            'filters' => json_encode(['video_ids' => array_values($ids)]),
            'max_count' => 20,
        ]);
    }

    /** Which settings this account's posts may use (privacy levels, max duration). */
    public function settings(): array
    {
        return $this->get('/business/video/settings/');
    }

    // ------------------------------------------------------------- publishing

    /** Hand TikTok a video URL; returns the publishing task (`share_id`). */
    public function publishVideo(string $videoUrl, array $postInfo): string
    {
        $data = $this->post('/business/video/publish/', [
            'video_url' => $videoUrl,
            // Required even when empty, and empty must encode as {} not [].
            'post_info' => $postInfo ?: new \stdClass(),
        ]);

        return $this->shareId($data);
    }

    /** @param  list<string>  $photoUrls */
    public function publishPhoto(array $photoUrls, array $postInfo, int $coverIndex = 0): string
    {
        $data = $this->post('/business/photo/publish/', [
            'photo_images' => array_values($photoUrls),
            'photo_cover_index' => $coverIndex,
            // Required even when empty, and empty must encode as {} not [].
            'post_info' => $postInfo ?: new \stdClass(),
        ]);

        return $this->shareId($data);
    }

    /** PROCESSING_DOWNLOAD | PUBLISH_COMPLETE | FAILED (+ post_ids / reason). */
    public function publishStatus(string $publishId): array
    {
        return $this->get('/business/publish/status/', ['publish_id' => $publishId]);
    }

    private function shareId(array $data): string
    {
        $id = $data['share_id'] ?? null;

        if (! $id) {
            throw new TikTokApiException('TikTok accepted the post but returned no publishing id.');
        }

        return (string) $id;
    }

    // --------------------------------------------------------------- comments

    /**
     * A page of top-level comments on one video, newest first, each with up to
     * three replies. ALL includes the hidden ones, which moderation needs.
     */
    public function comments(string $videoId, ?int $cursor = null, int $max = 30): array
    {
        return $this->get('/business/comment/list/', array_filter([
            'video_id' => $videoId,
            'include_replies' => 'true',
            'status' => 'ALL',
            'sort_field' => 'create_time',
            'sort_order' => 'desc',
            'max_count' => max(1, min(30, $max)),
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));
    }

    public function replies(string $videoId, string $commentId, ?int $cursor = null): array
    {
        return $this->get('/business/comment/reply/list/', array_filter([
            'video_id' => $videoId,
            'comment_id' => $commentId,
            'status' => 'ALL',
            'sort_field' => 'create_time',
            'sort_order' => 'asc',
            'max_count' => 30,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null));
    }

    public function createComment(string $videoId, string $text): array
    {
        return $this->post('/business/comment/create/', ['video_id' => $videoId, 'text' => $text]);
    }

    public function reply(string $videoId, string $commentId, string $text): array
    {
        return $this->post('/business/comment/reply/create/', [
            'video_id' => $videoId,
            'comment_id' => $commentId,
            'text' => $text,
        ]);
    }

    public function like(string $commentId, bool $like): void
    {
        $this->post('/business/comment/like/', ['comment_id' => $commentId, 'action' => $like ? 'LIKE' : 'UNLIKE']);
    }

    /** Hiding is how the account removes someone else's comment — it cannot delete one. */
    public function hide(string $videoId, string $commentId, bool $hide): void
    {
        $this->post('/business/comment/hide/', [
            'video_id' => $videoId,
            'comment_id' => $commentId,
            'action' => $hide ? 'HIDE' : 'UNHIDE',
        ]);
    }

    /** Only the account's own comments can be deleted. */
    public function delete(string $commentId): void
    {
        $this->post('/business/comment/delete/', ['comment_id' => $commentId]);
    }

    // ------------------------------------------------------------------- http

    private function token(): string
    {
        try {
            return (new TikTokChannel())->ensureValidAccessToken($this->connection);
        } catch (TikTokApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // An expired or revoked refresh token: the account has to come back
            // through Connect, which is the one remedy the page can offer.
            throw new TikTokApiException($e->getMessage(), 'reconnect_required', 422);
        }
    }

    private function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    private function post(string $path, array $body): array
    {
        return $this->send('post', $path, $body);
    }

    private function send(string $method, string $path, array $payload): array
    {
        $payload = ['business_id' => $this->businessId()] + $payload;
        $request = Http::acceptJson()->timeout(30)->withHeaders(['Access-Token' => $this->token()]);

        $response = $method === 'get'
            ? $request->get(TikTokAuthClient::API_BASE.$path, $payload)
            : $request->asJson()->post(TikTokAuthClient::API_BASE.$path, $payload);

        $json = $response->json() ?? [];

        // TikTok answers refusals with HTTP 200 and a non-zero code.
        if (! $response->successful() || (int) ($json['code'] ?? -1) !== 0) {
            throw TikTokApiException::fromResponse($json, $response->status());
        }

        return $json['data'] ?? [];
    }
}
