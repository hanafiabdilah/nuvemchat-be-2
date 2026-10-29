<?php

namespace App\Http\Controllers\Api\TikTok;

use App\Enums\TikTok\PostStatus;
use App\Exceptions\TikTokApiException;
use App\Http\Controllers\Api\TikTok\Concerns\ResolvesTikTokConnection;
use App\Http\Controllers\Controller;
use App\Http\Resources\TikTokPostResource;
use App\Jobs\PublishTikTokPost;
use App\Models\TikTokPost;
use App\Services\Media\MediaStorage;
use App\Services\TikTok\TikTokAccountsClientFactory;
use App\Services\TikTok\TikTokMediaPreparer;
use App\Services\TikTok\TikTokPresenter;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Composing, scheduling and publishing to a TikTok account.
 *
 * The same two halves as Instagram's grid: our rows for what TikTok has no
 * concept of (drafts, schedules, failures), and TikTok itself for what is live.
 * And the same absence: the Accounts API neither edits nor deletes a live post,
 * so a published post is read, never changed, from here.
 */
class TikTokPostController extends Controller
{
    use ResolvesTikTokConnection;

    /** TikTok's own limits. */
    private const MAX_VIDEO_CAPTION = 2200;
    private const MAX_PHOTO_CAPTION = 4000;
    private const MAX_PHOTO_TITLE = 90;
    private const MAX_PHOTOS = 35;

    private const PRIVACY = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

    public function __construct(
        private readonly TikTokAccountsClientFactory $clients,
        private readonly TikTokMediaPreparer $media,
    ) {}

    /**
     * The account's feed: everything waiting, then a page of what is live.
     *
     * Our rows do not depend on TikTok — when TikTok cannot be read, the
     * drafts and schedules still come back with the reason beside them. Only a
     * missing permission stops the page, because nothing on it can work then.
     */
    public function index(Request $request, string $connectionId)
    {
        $connection = $this->tiktokConnection($request, $connectionId);
        $client = $this->clients->for($connection);
        $client->assertPostPermissions();

        $pending = TikTokPost::with(['items', 'creator'])
            ->where('connection_id', $connection->id)
            ->whereIn('status', PostStatus::pending())
            // One timeline from the future into the past, as on Instagram:
            // drafts above, then schedules furthest-out first.
            ->orderByRaw('scheduled_at is not null')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->get();

        $cursor = $request->filled('cursor') ? (int) $request->input('cursor') : null;

        try {
            $page = $client->videos($cursor);
        } catch (TikTokApiException $e) {
            if ($e->needsReconnect() || $cursor !== null) {
                throw $e;
            }

            return response()->json([
                'pending' => TikTokPostResource::collection($pending)->resolve(),
                'published' => [],
                'next_cursor' => null,
                'has_more' => false,
                'tiktok_error' => UpstreamError::message(
                    UpstreamProvider::TikTok,
                    $e->getMessage(),
                    $e->tiktokCode(),
                    $e->httpStatus(),
                    ['connection_id' => $connection->id, 'operation' => 'tiktok.feed'],
                ),
            ]);
        }

        return response()->json([
            'pending' => TikTokPostResource::collection($pending)->resolve(),
            'published' => array_map(fn (array $video) => TikTokPresenter::video($video), $page['videos'] ?? []),
            'next_cursor' => isset($page['cursor']) ? (string) $page['cursor'] : null,
            'has_more' => (bool) ($page['has_more'] ?? false),
        ]);
    }

    public function show(Request $request, string $id)
    {
        return new TikTokPostResource($this->findForTenant($request, $id));
    }

    /** One photo, re-encoded to what TikTok accepts. Videos come in pieces (uploadChunk). */
    public function upload(Request $request, string $connectionId)
    {
        $connection = $this->tiktokConnection($request, $connectionId);

        $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv(TikTokMediaPreparer::MAX_PHOTO_BYTES, 1024) * 2],
        ]);

        return response()->json([
            'data' => $this->media->storePhoto($request->file('file'), (int) $connection->tenant_id),
        ], 201);
    }

    /** One piece of a video (see ChunkedUpload). */
    public function uploadChunk(Request $request, string $connectionId)
    {
        $connection = $this->tiktokConnection($request, $connectionId);

        $data = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:100000'],
            'size' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'chunk' => ['required', 'file', 'max:'.(intdiv(\App\Services\Media\ChunkedUpload::CHUNK_BYTES, 1024) + 64)],
        ]);

        $result = $this->media->chunks()->receive(
            (int) $connection->tenant_id,
            TikTokMediaPreparer::directory((int) $connection->tenant_id),
            $data['upload_id'],
            (int) $data['index'],
            (int) $data['total'],
            (int) $data['size'],
            $data['name'],
            $request->file('chunk'),
        );

        if (! $result['done']) {
            return response()->json(['data' => ['done' => false, 'next_index' => $result['next_index']]]);
        }

        return response()->json(['data' => ['done' => true] + TikTokMediaPreparer::videoFile($result['file']['path'])], 201);
    }

    /**
     * Create a post: as a draft, for later, or right now. Publishing now needs
     * `publish`; a schedule only `create`, the same split as Instagram's.
     */
    public function store(Request $request, string $connectionId)
    {
        $connection = $this->tiktokConnection($request, $connectionId);
        $data = $this->validatePayload($request, (int) $connection->tenant_id);
        $publishNow = $request->boolean('publish_now');

        if ($publishNow && ! $request->user()->can('tiktok-posts.publish')) {
            throw new HttpException(403, 'You may draft posts, but not publish them.');
        }

        $post = DB::transaction(function () use ($request, $connection, $data, $publishNow) {
            $post = TikTokPost::create($this->attributes($data, $publishNow) + [
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->id,
                'created_by' => $request->user()->id,
            ]);

            $this->syncItems($post, $data['items']);

            return $post;
        });

        if ($publishNow) {
            PublishTikTokPost::dispatch($post->id);
        }

        return (new TikTokPostResource($post->fresh(['items', 'creator'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if (! $post->status->isEditable()) {
            throw new HttpException(422, 'This post is already on its way to TikTok and can no longer be edited.');
        }

        $data = $this->validatePayload($request, (int) $post->tenant_id);

        DB::transaction(function () use ($post, $data) {
            $post->update($this->attributes($data, false) + [
                // A retry starts clean: an old publishing task must not be
                // polled for a post whose media changed.
                'share_id' => null,
                'attempts' => 0,
                'error' => null,
            ]);

            $this->syncItems($post, $data['items']);
        });

        return new TikTokPostResource($post->fresh(['items', 'creator']));
    }

    /** Publish a draft, a schedule, or a failed post, now. */
    public function publish(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if (! $post->status->isPublishable()) {
            throw new HttpException(422, 'This post is already on its way to TikTok.');
        }

        if ($post->items->isEmpty()) {
            throw new HttpException(422, 'Add a video or photos before publishing this post.');
        }

        // A failed post retried starts a new publishing task.
        $post->update(['status' => PostStatus::Queued, 'error' => null, 'share_id' => null, 'attempts' => 0]);

        PublishTikTokPost::dispatch($post->id);

        return new TikTokPostResource($post->fresh(['items', 'creator']));
    }

    /**
     * Delete one of ours. A published post can only be removed in the TikTok
     * app — the Accounts API has no delete — so refusing here is honest.
     */
    public function destroy(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if ($post->status === PostStatus::Published) {
            throw new HttpException(422, 'Published posts can only be removed from the TikTok app.');
        }

        if ($post->status->isInFlight()) {
            throw new HttpException(422, 'This post is being published right now. Wait for it to finish.');
        }

        DB::transaction(function () use ($post) {
            $post->items->each(fn ($item) => $item->path ? MediaStorage::published()->delete($item->path) : null);
            $post->items()->delete();
            $post->delete();
        });

        return response()->json(['message' => 'Post deleted.']);
    }

    // ---------------------------------------------------------------- internals

    private function findForTenant(Request $request, string $id): TikTokPost
    {
        $post = TikTokPost::with(['items', 'creator', 'connection'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->find($id);

        if (! $post) {
            throw new HttpException(404, 'Post not found.');
        }

        if (! $request->user()->canAccessConnection($post->connection_id)) {
            throw new HttpException(403, 'You do not have access to this TikTok account.');
        }

        return $post;
    }

    /**
     * The composer's payload, with TikTok's rules applied before TikTok would.
     * A draft may have no media yet; the moment it is scheduled or sent the
     * shape rules apply.
     */
    private function validatePayload(Request $request, int $tenantId): array
    {
        $isDraft = ! $request->boolean('publish_now') && blank($request->input('scheduled_at'));
        $isPhoto = $request->input('media_type') === TikTokPost::MEDIA_PHOTO;

        $data = $request->validate([
            'media_type' => ['required', Rule::in([TikTokPost::MEDIA_VIDEO, TikTokPost::MEDIA_PHOTO])],
            'title' => ['nullable', 'string', 'max:'.self::MAX_PHOTO_TITLE],
            'caption' => ['nullable', 'string', 'max:'.($isPhoto ? self::MAX_PHOTO_CAPTION : self::MAX_VIDEO_CAPTION)],
            'privacy_level' => ['nullable', Rule::in(self::PRIVACY)],
            'disable_comment' => ['sometimes', 'boolean'],
            'disable_duet' => ['sometimes', 'boolean'],
            'disable_stitch' => ['sometimes', 'boolean'],
            'is_ai_generated' => ['sometimes', 'boolean'],
            'is_brand_organic' => ['sometimes', 'boolean'],
            'is_branded_content' => ['sometimes', 'boolean'],
            'auto_add_music' => ['sometimes', 'boolean'],
            'items' => [$isDraft ? 'nullable' : 'required', 'array', 'max:'.self::MAX_PHOTOS],
            'items.*.path' => ['required', 'string', 'max:1024'],
            'items.*.media_type' => ['required', Rule::in(['image', 'video'])],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $data['items'] = $data['items'] ?? [];
        $count = count($data['items']);

        foreach ($data['items'] as $i => $item) {
            $this->assertOwnFile($item['path'], $tenantId);

            // What a file is comes from the file, not from the request.
            $data['items'][$i]['media_type'] = in_array(strtolower(pathinfo($item['path'], PATHINFO_EXTENSION)), TikTokMediaPreparer::VIDEO_EXTENSIONS, true)
                ? 'video'
                : 'image';
        }

        if ($count === 0) {
            return $data;
        }

        $videos = collect($data['items'])->where('media_type', 'video')->count();

        if ($isPhoto && $videos > 0) {
            throw ValidationException::withMessages(['items' => 'A photo post cannot contain a video.']);
        }

        if (! $isPhoto && ($count !== 1 || $videos !== 1)) {
            throw ValidationException::withMessages(['items' => 'A video post takes exactly one video.']);
        }

        return $data;
    }

    /**
     * The path names a file this workspace uploaded — the URL TikTok fetches is
     * derived from it here, never taken from the request, so a crafted payload
     * cannot make TikTok publish someone else's file.
     */
    private function assertOwnFile(string $path, int $tenantId): void
    {
        $prefix = TikTokMediaPreparer::directory($tenantId).'/';

        if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! MediaStorage::published()->exists($path)) {
            throw ValidationException::withMessages(['items' => 'Upload the media again.']);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $data, bool $publishNow): array
    {
        $isPhoto = $data['media_type'] === TikTokPost::MEDIA_PHOTO;

        return [
            'status' => $publishNow
                ? PostStatus::Queued
                : (filled($data['scheduled_at'] ?? null) ? PostStatus::Scheduled : PostStatus::Draft),
            'media_type' => $data['media_type'],
            'title' => $isPhoto ? ($data['title'] ?? null) : null,
            'caption' => $data['caption'] ?? null,
            'privacy_level' => $isPhoto ? ($data['privacy_level'] ?? 'PUBLIC_TO_EVERYONE') : null,
            'disable_comment' => (bool) ($data['disable_comment'] ?? false),
            'disable_duet' => $isPhoto ? false : (bool) ($data['disable_duet'] ?? false),
            'disable_stitch' => $isPhoto ? false : (bool) ($data['disable_stitch'] ?? false),
            'is_ai_generated' => $isPhoto ? false : (bool) ($data['is_ai_generated'] ?? false),
            'is_brand_organic' => (bool) ($data['is_brand_organic'] ?? false),
            'is_branded_content' => (bool) ($data['is_branded_content'] ?? false),
            'auto_add_music' => $isPhoto && (bool) ($data['auto_add_music'] ?? false),
            // "Post now" wins over a date left in the form; a draft keeps none.
            'scheduled_at' => $publishNow ? null : ($data['scheduled_at'] ?? null),
        ];
    }

    /** Replace the media wholesale, giving back files no longer referenced. */
    private function syncItems(TikTokPost $post, array $items): void
    {
        $keep = collect($items)->pluck('path')->all();

        $post->items()->whereNotIn('path', $keep)->get()
            ->each(fn ($item) => $item->path ? MediaStorage::published()->delete($item->path) : null);

        $post->items()->delete();

        foreach (array_values($items) as $position => $item) {
            $post->items()->create([
                'position' => $position,
                'media_type' => $item['media_type'],
                'path' => $item['path'],
                'url' => MediaStorage::publishedUrl($item['path']),
            ]);
        }

        $post->load('items');
    }
}
