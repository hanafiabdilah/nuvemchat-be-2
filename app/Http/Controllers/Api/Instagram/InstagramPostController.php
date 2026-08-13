<?php

namespace App\Http\Controllers\Api\Instagram;

use App\Enums\Instagram\PostMediaType;
use App\Enums\Instagram\PostStatus;
use App\Http\Controllers\Api\Instagram\Concerns\ResolvesInstagramConnection;
use App\Http\Controllers\Controller;
use App\Http\Resources\InstagramPostResource;
use App\Jobs\PublishInstagramPost;
use App\Models\InstagramPost;
use App\Services\Instagram\InstagramGraphClientFactory;
use App\Services\Instagram\InstagramMediaPreparer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Composing, scheduling and publishing to an Instagram account.
 *
 * The grid this feeds is two things stitched together, and they are kept apart
 * here on purpose. What is already live comes from Instagram on every request —
 * it owns the likes, the comment counts and the permalink, and a cached copy of
 * those would only ever be wrong. What has not been published yet comes from
 * our own table, because Instagram has no concept of a draft or a schedule.
 */
class InstagramPostController extends Controller
{
    use ResolvesInstagramConnection;

    /** Instagram's own caption ceiling. */
    private const MAX_CAPTION = 2200;

    public function __construct(
        private readonly InstagramGraphClientFactory $clients,
        private readonly InstagramMediaPreparer $media,
    ) {}

    /**
     * The account's feed: everything waiting, then everything live.
     *
     * Pending posts are not paginated — a queue of drafts and schedules that
     * needed paging would be a sign something has gone wrong, and the grid
     * wants all of them pinned above the published tiles anyway.
     */
    public function index(Request $request, string $connectionId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $pending = InstagramPost::with(['items', 'creator'])
            ->where('connection_id', $connection->id)
            ->whereIn('status', [
                PostStatus::Draft,
                PostStatus::Scheduled,
                PostStatus::Queued,
                PostStatus::Publishing,
                PostStatus::Failed,
            ])
            // Soonest first, drafts (no date) last: the top of the grid should
            // be what happens next.
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at')
            ->orderByDesc('id')
            ->get();

        $client = $this->clients->for($connection);
        $after = $request->string('after')->toString() ?: null;

        $feed = $client->media((int) $request->integer('limit', 24), $after);

        return response()->json([
            'pending' => InstagramPostResource::collection($pending)->resolve(),
            'published' => $feed['data'] ?? [],
            // Stories live on their own edge and are never in `media`, so they
            // need a second call. Skipped when paging: the strip belongs to the
            // first screen, and re-fetching it per page would be a Graph call
            // per "Load more" for a list that has not changed.
            'stories' => $after ? [] : $this->stories($client),
            // Instagram pages by cursor, not by page number. Null means the end.
            'next_cursor' => $feed['paging']['cursors']['after'] ?? null,
            'has_more' => isset($feed['paging']['next']),
        ]);
    }

    /**
     * Stories, best-effort.
     *
     * Deliberately swallowed on failure: the feed call above runs first and is
     * what reports a dead token or a missing scope, so letting this one raise
     * would replace a working grid with an error over a strip that is empty
     * most of the day anyway.
     */
    private function stories(\App\Services\Instagram\InstagramGraphClient $client): array
    {
        try {
            return $client->stories()['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Could not read Instagram stories', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function show(Request $request, string $id)
    {
        return new InstagramPostResource($this->findForTenant($request, $id));
    }

    /**
     * Take an upload and hand back a URL Instagram can fetch.
     *
     * Separate from `store` because the content publishing API does not accept
     * bytes for images — it accepts an address and downloads from it — so the
     * file has to exist publicly before a post can reference it. Splitting it
     * also lets the composer show a real preview of what will be posted,
     * cropping and all, before anything is scheduled.
     */
    public function upload(Request $request, string $connectionId)
    {
        $connection = $this->instagramConnection($request, $connectionId);

        $request->validate([
            // 100 MB: Instagram's own video ceiling is higher, but anything
            // larger is a reel that will not survive its own upload anyway.
            'file' => ['required', 'file', 'max:102400'],
            'fit' => ['nullable', Rule::in([InstagramMediaPreparer::FIT_CROP, InstagramMediaPreparer::FIT_PAD])],
        ]);

        return response()->json([
            'data' => $this->media->store(
                $request->file('file'),
                (int) $connection->tenant_id,
                $request->string('fit')->toString() ?: InstagramMediaPreparer::FIT_CROP,
            ),
        ], 201);
    }

    /**
     * Create a post: as a draft, for later, or right now.
     *
     * `publish_now` is checked against a separate permission rather than the
     * one that got the caller this far. Drafting a post and putting it in front
     * of the company's whole following are different levels of trust — the same
     * split campaigns make between create and send.
     */
    public function store(Request $request, string $connectionId)
    {
        $connection = $this->instagramConnection($request, $connectionId);
        $data = $this->validatePayload($request);

        $publishNow = $request->boolean('publish_now');

        if ($publishNow && ! $request->user()->can('instagram-posts.publish')) {
            throw new HttpException(403, 'You may draft posts, but not publish them.');
        }

        $post = DB::transaction(function () use ($request, $connection, $data, $publishNow) {
            $post = InstagramPost::create([
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->id,
                'created_by' => $request->user()->id,
                // Queued, not Draft: the row goes back to the browser before
                // the worker has touched it, and saying Draft there made a
                // successful "Publish now" look like it had done nothing.
                'status' => $publishNow ? PostStatus::Queued : $this->initialStatus($data),
                'media_type' => $data['media_type'],
                'caption' => $data['caption'] ?? null,
                // "Post now" wins over any date left in the form: the row would
                // otherwise stay Scheduled and be dispatched a second time when
                // that date arrived.
                'scheduled_at' => $publishNow ? null : ($data['scheduled_at'] ?? null),
            ]);

            $this->syncItems($post, $data['items']);

            return $post;
        });

        if ($publishNow) {
            PublishInstagramPost::dispatch($post->id);
        }

        return (new InstagramPostResource($post->fresh(['items', 'creator'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Edit a post that has not gone out yet.
     *
     * Once published there is nothing to edit: the Instagram Login API accepts
     * no caption change and no media change on live posts, so allowing the form
     * to open would only promise something we cannot deliver.
     */
    public function update(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if (! $post->status->isEditable()) {
            throw new HttpException(422, 'This post has already been published and can no longer be edited.');
        }

        $data = $this->validatePayload($request);

        DB::transaction(function () use ($post, $data) {
            $post->update([
                'status' => $this->initialStatus($data),
                'media_type' => $data['media_type'],
                'caption' => $data['caption'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                // A retry of a failed post starts clean: stale container ids
                // would make the publisher reuse containers built for media
                // that has since been replaced.
                'ig_container_id' => null,
                'attempts' => 0,
                'error' => null,
            ]);

            $this->syncItems($post, $data['items']);
        });

        return new InstagramPostResource($post->fresh(['items', 'creator']));
    }

    /** Publish a draft, a schedule, or a failed post, now. */
    public function publish(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if (! $post->status->isPublishable()) {
            throw new HttpException(422, 'This post is already on its way to Instagram.');
        }

        // Stamped before the dispatch, so the response already says what is
        // happening and a second press finds a post that is no longer
        // publishable rather than queueing it twice.
        $post->update(['status' => PostStatus::Queued, 'error' => null]);

        PublishInstagramPost::dispatch($post->id);

        return new InstagramPostResource($post->fresh(['items', 'creator']));
    }

    /**
     * Delete one of ours.
     *
     * Note what this cannot do: remove a post from Instagram. The delete
     * endpoint exists only on the Facebook Login flavour of the API and needs
     * `instagram_manage_contents`, which has no Instagram Login counterpart —
     * so a published post can only be taken down from the Instagram app itself.
     * Refusing here is honest; deleting our row would just hide a post that is
     * still live.
     */
    public function destroy(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if ($post->status === PostStatus::Published) {
            throw new HttpException(422, 'Published posts can only be removed from the Instagram app.');
        }

        if ($post->status->isInFlight()) {
            throw new HttpException(422, 'This post is being published right now. Wait for it to finish.');
        }

        DB::transaction(function () use ($post) {
            $this->discardFiles($post);
            $post->items()->delete();
            $post->delete();
        });

        return response()->json(['message' => 'Post deleted.']);
    }

    // -------------------------------------------------------------- internals

    private function findForTenant(Request $request, string $id): InstagramPost
    {
        $post = InstagramPost::with(['items', 'creator', 'connection'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->find($id);

        if (! $post) {
            throw new HttpException(404, 'Post not found.');
        }

        // Access is decided by the connection, not by the row: an agent whose
        // access to the account was revoked must lose its posts with it.
        if (! $request->user()->canAccessConnection($post->connection_id)) {
            throw new HttpException(403, 'You do not have access to this Instagram account.');
        }

        return $post;
    }

    /**
     * Validate the composer's payload, including the rules that depend on which
     * kind of post it is.
     *
     * Meta enforces all of these too, but only after a container has been built
     * and (for a carousel) every child uploaded — so catching them here is the
     * difference between an inline form error and a post that fails minutes
     * later for reasons the user never sees.
     */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'media_type' => ['required', Rule::enum(PostMediaType::class)],
            'caption' => ['nullable', 'string', 'max:' . self::MAX_CAPTION],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.url' => ['required', 'string', 'max:2048'],
            'items.*.path' => ['nullable', 'string', 'max:1024'],
            'items.*.media_type' => ['required', Rule::in(['image', 'video'])],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $type = PostMediaType::from($data['media_type']);
        [$min, $max] = $type->itemRange();
        $count = count($data['items']);

        if ($count < $min || $count > $max) {
            throw ValidationException::withMessages([
                'items' => $type->isCarousel()
                    ? 'A carousel needs between 2 and 10 items.'
                    : 'This post type takes exactly one photo or video.',
            ]);
        }

        $hasVideo = collect($data['items'])->contains(fn ($item) => $item['media_type'] === 'video');

        if ($type === PostMediaType::Image && $hasVideo) {
            throw ValidationException::withMessages(['items' => 'A photo post cannot contain a video.']);
        }

        if (in_array($type, [PostMediaType::Video, PostMediaType::Reels], true) && ! $hasVideo) {
            throw ValidationException::withMessages(['items' => 'A video post needs a video file.']);
        }

        if (! $type->supportsCaption()) {
            // Dropped rather than refused: Instagram ignores a caption on a
            // story, and failing the request over something Meta silently
            // discards would be pedantry.
            $data['caption'] = null;
        }

        return $data;
    }

    /** A date makes it a schedule; its absence makes it a draft. */
    private function initialStatus(array $data): PostStatus
    {
        return filled($data['scheduled_at'] ?? null)
            ? PostStatus::Scheduled
            : PostStatus::Draft;
    }

    /**
     * Replace the post's media wholesale.
     *
     * Wholesale rather than patched because the items are positional and a
     * carousel's order is the thing users reorder most; diffing them would buy
     * nothing but a way to get the order wrong. Files that are no longer
     * referenced are removed so an edited draft does not leak storage.
     */
    private function syncItems(InstagramPost $post, array $items): void
    {
        $keepUrls = collect($items)->pluck('url')->all();

        $post->items()
            ->whereNotIn('url', $keepUrls)
            ->get()
            ->each(function ($item) {
                if ($item->path) {
                    Storage::disk('public')->delete($item->path);
                }
            });

        $post->items()->delete();

        foreach (array_values($items) as $position => $item) {
            $post->items()->create([
                'position' => $position,
                'media_type' => $item['media_type'],
                'url' => $item['url'],
                'path' => $item['path'] ?? null,
            ]);
        }

        $post->load('items');
    }

    private function discardFiles(InstagramPost $post): void
    {
        $post->items->each(function ($item) {
            if ($item->path) {
                Storage::disk('public')->delete($item->path);
            }
        });
    }
}
