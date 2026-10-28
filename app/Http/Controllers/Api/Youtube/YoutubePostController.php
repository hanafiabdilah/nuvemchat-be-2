<?php

namespace App\Http\Controllers\Api\Youtube;

use App\Enums\Youtube\PostStatus;
use App\Exceptions\YoutubeApiException;
use App\Http\Controllers\Api\Youtube\Concerns\ResolvesYoutubeAccount;
use App\Http\Controllers\Controller;
use App\Http\Resources\YoutubePostResource;
use App\Jobs\PublishYoutubePost;
use App\Models\AuditLog;
use App\Models\YoutubeAccount;
use App\Models\YoutubePost;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use App\Services\Media\UploadPolicy;
use App\Services\Youtube\YoutubeChunkedUpload;
use App\Services\Youtube\YoutubeClientFactory;
use App\Services\Youtube\YoutubePresenter;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Composing, uploading and managing videos on a YouTube channel.
 *
 * The grid is the same two halves stitched together as Instagram's: our rows
 * for what is not on YouTube yet (drafts, uploads on their way, failures), and
 * YouTube itself for everything else, read on every request.
 *
 * Where it differs is what can be done to a live post. The YouTube API edits a
 * video's title, description, tags, category, audience and visibility, moves or
 * cancels a schedule, and deletes it outright — so the live half is managed
 * here too, not only looked at.
 */
class YoutubePostController extends Controller
{
    use ResolvesYoutubeAccount;

    /** YouTube's own ceilings. */
    private const MAX_TITLE = 100;
    private const MAX_DESCRIPTION = 5000;
    private const MAX_TAGS_CHARS = 500;

    public function __construct(
        private readonly YoutubeClientFactory $clients,
    ) {}

    /**
     * The channel's feed: what is waiting here, then a page of its uploads.
     *
     * On the first page, videos YouTube is holding for a scheduled time are
     * lifted to the top, furthest-out first — the grid reads as one timeline
     * from the future into the past, like Instagram's. (Paged by YouTube's own
     * token; the playlist order is upload time, so a video scheduled for next
     * week but uploaded last month would otherwise sit buried.)
     */
    public function index(Request $request, string $accountId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $pending = YoutubePost::with('creator')
            ->where('youtube_account_id', $account->id)
            ->whereIn('status', PostStatus::pending())
            ->orderByDesc('id')
            ->get();

        $after = $request->string('after')->toString() ?: null;
        $client = $this->clients->for($account);

        // Our rows do not depend on Google. When YouTube cannot be read, the
        // drafts and failed uploads are still shown, with the reason beside
        // them — only a grant Google stopped honouring stops the page, since
        // nothing on it can work until the channel is connected again.
        try {
            $playlist = $account->uploads_playlist_id ?: $this->uploadsPlaylist($account, $client);
            $page = $playlist
                ? $client->uploads($playlist, (int) $request->integer('limit', 24), $after)
                : ['items' => [], 'next_page_token' => null];
        } catch (YoutubeApiException $e) {
            if ($e->needsReconnect() || $after) {
                throw $e;
            }

            return response()->json([
                'pending' => YoutubePostResource::collection($pending)->resolve(),
                'published' => [],
                'next_cursor' => null,
                'has_more' => false,
                'youtube_error' => UpstreamError::message(
                    UpstreamProvider::Youtube,
                    $e->getMessage(),
                    $e->reason(),
                    $e->httpStatus(),
                    ['youtube_account_id' => $account->id, 'operation' => 'youtube.feed'],
                ),
            ]);
        }

        $videos = array_map(fn (array $video) => YoutubePresenter::video($video), $page['items']);

        if (! $after) {
            $scheduled = array_values(array_filter($videos, fn ($video) => $video['is_scheduled']));
            usort($scheduled, fn ($a, $b) => strtotime((string) $b['publish_at']) <=> strtotime((string) $a['publish_at']));
            $videos = array_merge($scheduled, array_values(array_filter($videos, fn ($video) => ! $video['is_scheduled'])));
        }

        return response()->json([
            'pending' => YoutubePostResource::collection($pending)->resolve(),
            'published' => $videos,
            'next_cursor' => $page['next_page_token'],
            'has_more' => $page['next_page_token'] !== null,
        ]);
    }

    public function show(Request $request, string $id)
    {
        return new YoutubePostResource($this->findForTenant($request, $id));
    }

    /**
     * Take the video file.
     *
     * Separate from saving the post, like Instagram's upload, so the composer
     * can show the file while the rest is written. The file goes to the
     * private media disk: unlike Meta, YouTube does not fetch from a URL — we
     * send it the bytes — so nothing about it needs to be public.
     */
    public function upload(Request $request, string $accountId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', YoutubeChunkedUpload::EXTENSIONS),
                'max:'.((int) config('youtube.max_upload_mb', 1024) * 1024),
            ],
        ]);

        $file = $request->file('file');

        $path = MediaFilename::path(
            $this->directory((int) $account->tenant_id),
            MediaFilename::token(),
            $file->getClientOriginalName(),
            UploadPolicy::storedExtension($file),
        );

        MediaStorage::disk()->putFileAs(dirname($path), $file, basename($path));

        return response()->json(['data' => $this->videoFile($path)], 201);
    }

    /**
     * Take one piece of a video sent in pieces — the way the dashboard always
     * sends them, since production PHP refuses any single request over 64 MB.
     * See YoutubeChunkedUpload.
     */
    public function uploadChunk(Request $request, string $accountId, YoutubeChunkedUpload $chunks)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $data = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:100000'],
            'size' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            // A little over one piece, for the multipart envelope.
            'chunk' => ['required', 'file', 'max:'.(intdiv(YoutubeChunkedUpload::CHUNK_BYTES, 1024) + 64)],
        ]);

        $result = $chunks->receive(
            (int) $account->tenant_id,
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

        return response()->json(['data' => ['done' => true] + self::videoFile($result['file']['path'])], 201);
    }

    /**
     * Create a post: a draft, an upload right now, or a schedule.
     *
     * A schedule is uploaded right away, as private with `publishAt`; YouTube
     * publishes it on time. That moves the failure a scheduled post can have
     * — quota, a refused file — to the moment someone is looking at the
     * screen, instead of the moment it was supposed to go live.
     *
     * Uploading now is checked against `publish` separately: drafting a video
     * and putting it in front of the channel's subscribers are different
     * levels of trust. A schedule only needs `create`, as on Instagram: it
     * goes out on its own, at the time someone with the right already agreed
     * to by being able to draft.
     */
    public function store(Request $request, string $accountId)
    {
        $account = $this->youtubeAccount($request, $accountId);
        $data = $this->validatePayload($request, (int) $account->tenant_id);
        $mode = $this->mode($request);

        if ($mode === 'now' && ! $request->user()->can('youtube-posts.publish')) {
            throw new HttpException(403, 'You may draft videos, but not publish them.');
        }

        $post = YoutubePost::create($this->attributes($data, $mode) + [
            'tenant_id' => $account->tenant_id,
            'youtube_account_id' => $account->id,
            'created_by' => $request->user()->id,
        ]);

        if ($mode !== 'draft') {
            PublishYoutubePost::dispatch($post->id);
        }

        return (new YoutubePostResource($post->fresh('creator')))->response()->setStatusCode(201);
    }

    /** Edit a post that has not reached YouTube (a draft, or one that failed). */
    public function update(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if (! $post->status->isEditable()) {
            throw new HttpException(422, 'This video is already on its way to YouTube and can no longer be edited here.');
        }

        $data = $this->validatePayload($request, (int) $post->tenant_id);
        $mode = $this->mode($request);

        if ($mode === 'now' && ! $request->user()->can('youtube-posts.publish')) {
            throw new HttpException(403, 'You may draft videos, but not publish them.');
        }

        $previousPath = $post->video_path;

        $post->update($this->attributes($data, $mode) + [
            // A new file means a new upload: a session opened for the old file
            // must not be resumed with the new one's bytes.
            'upload_url' => null,
            'uploaded_bytes' => 0,
            'attempts' => 0,
            'error' => null,
        ]);

        if ($previousPath && $previousPath !== $post->video_path) {
            MediaStorage::disk()->delete($previousPath);
        }

        if ($mode !== 'draft') {
            PublishYoutubePost::dispatch($post->id);
        }

        return new YoutubePostResource($post->fresh('creator'));
    }

    /** Send a draft, or retry a failed upload, now. */
    public function publish(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if (! $post->status->isPublishable()) {
            throw new HttpException(422, 'This video is already on its way to YouTube.');
        }

        if (! $post->video_path) {
            throw new HttpException(422, 'Add a video file before publishing.');
        }

        if (blank($post->title)) {
            throw new HttpException(422, 'Give the video a title before publishing.');
        }

        // A schedule whose time has passed while it sat as a draft or a failure
        // goes out now, as asked — not with a publishAt YouTube would refuse.
        $post->update([
            'status' => PostStatus::Queued,
            'error' => null,
            'scheduled_at' => $post->scheduled_at?->isFuture() ? $post->scheduled_at : null,
        ]);

        PublishYoutubePost::dispatch($post->id);

        return new YoutubePostResource($post->fresh('creator'));
    }

    /** Delete one of ours: a draft or a failed upload. */
    public function destroy(Request $request, string $id)
    {
        $post = $this->findForTenant($request, $id);

        if ($post->status->isInFlight()) {
            throw new HttpException(422, 'This video is being uploaded right now. Wait for it to finish.');
        }

        if ($post->status === PostStatus::Published) {
            throw new HttpException(422, 'This video is already on YouTube. Delete it from the channel feed instead.');
        }

        DB::transaction(function () use ($post) {
            if ($post->video_path) {
                MediaStorage::disk()->delete($post->video_path);
            }

            $post->delete();
        });

        return response()->json(['message' => 'Post deleted.']);
    }

    // ------------------------------------------------------- live videos

    /** The categories the composer and the edit form offer, in the reader's language. */
    public function categories(Request $request, string $accountId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $region = strtoupper((string) ($account->tenant?->market_code ?: 'BR'));
        $hl = preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', (string) $request->query('hl')) ? (string) $request->query('hl') : 'en';

        $categories = Cache::remember("youtube:categories:{$region}:{$hl}", now()->addDay(), function () use ($account, $region, $hl) {
            return collect($this->clients->for($account)->categories($region, $hl))
                // Some categories exist for old videos only; YouTube refuses
                // them on new uploads.
                ->filter(fn ($category) => ($category['snippet']['assignable'] ?? false) === true)
                ->map(fn ($category) => [
                    'id' => (string) $category['id'],
                    'title' => (string) ($category['snippet']['title'] ?? $category['id']),
                ])
                ->sortBy('title')
                ->values()
                ->all();
        });

        return response()->json(['data' => $categories]);
    }

    /**
     * Change a live video: its words, its audience, its visibility, or its
     * schedule.
     *
     * Read first and merged, never sent partially — videos.update resets
     * every field of a part it is sent and leaves out (see YoutubeClient).
     */
    public function updateVideo(Request $request, string $accountId, string $videoId)
    {
        $account = $this->youtubeAccount($request, $accountId);
        $client = $this->clients->for($account);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE, 'not_regex:/[<>]/'],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION, 'not_regex:/[<>]/'],
            'tags' => ['nullable', 'array', 'max:60'],
            'tags.*' => ['string', 'max:100'],
            'category_id' => ['nullable', 'string', 'regex:/^\d{1,4}$/'],
            'privacy_status' => ['required', Rule::in(YoutubePost::PRIVACY)],
            'made_for_kids' => ['sometimes', 'boolean'],
            'publish_at' => ['nullable', 'date', 'after:now'],
        ]);

        $this->assertTagsFit($data['tags'] ?? []);

        $video = $client->video($videoId);

        if (! $video || ($video['snippet']['channelId'] ?? null) !== $account->channel_id) {
            throw new HttpException(404, 'Video not found on this channel.');
        }

        $snippet = array_filter([
            'title' => $data['title'],
            'description' => (string) ($data['description'] ?? ''),
            'tags' => array_values(array_filter(array_map('trim', $data['tags'] ?? []))) ?: null,
            'categoryId' => ($data['category_id'] ?? null) ?: ($video['snippet']['categoryId'] ?? '22'),
            'defaultLanguage' => $video['snippet']['defaultLanguage'] ?? null,
            'defaultAudioLanguage' => $video['snippet']['defaultAudioLanguage'] ?? null,
        ], fn ($value) => $value !== null);

        $current = $video['status'] ?? [];
        $status = array_filter([
            'privacyStatus' => $data['privacy_status'],
            'embeddable' => $current['embeddable'] ?? null,
            'license' => $current['license'] ?? null,
            'publicStatsViewable' => $current['publicStatsViewable'] ?? null,
            'selfDeclaredMadeForKids' => array_key_exists('made_for_kids', $data)
                ? (bool) $data['made_for_kids']
                : ($current['selfDeclaredMadeForKids'] ?? null),
        ], fn ($value) => $value !== null);

        // A schedule only exists on a private video; setting one makes the
        // video private until then, and leaving it empty cancels it.
        if (! empty($data['publish_at'])) {
            $status['privacyStatus'] = 'private';
            $status['publishAt'] = Carbon::parse($data['publish_at'])->utc()->toIso8601ZuluString();
        }

        $updated = $client->updateVideo($videoId, $snippet, $status);

        YoutubePost::where('youtube_account_id', $account->id)
            ->where('yt_video_id', $videoId)
            ->update(['scheduled_at' => ! empty($data['publish_at']) ? Carbon::parse($data['publish_at']) : null]);

        AuditLog::record('youtube.video_updated', "Edited YouTube video {$videoId}", [
            'youtube_account_id' => $account->id,
            'video_id' => $videoId,
        ]);

        // videos.update answers with the parts it was sent, which leaves the
        // statistics out; keep the ones we just read.
        return response()->json([
            'data' => YoutubePresenter::video($updated + ['statistics' => $video['statistics'] ?? [], 'contentDetails' => $video['contentDetails'] ?? []]),
        ]);
    }

    /**
     * Delete a video from YouTube.
     *
     * Final, and visible to everyone as an absence — the dashboard asks for
     * confirmation and says so. Possible here, unlike Instagram, because the
     * YouTube API offers it.
     */
    public function destroyVideo(Request $request, string $accountId, string $videoId)
    {
        $account = $this->youtubeAccount($request, $accountId);

        $this->clients->for($account)->deleteVideo($videoId);

        YoutubePost::where('youtube_account_id', $account->id)->where('yt_video_id', $videoId)->delete();

        AuditLog::record('youtube.video_deleted', "Deleted YouTube video {$videoId}", [
            'youtube_account_id' => $account->id,
            'video_id' => $videoId,
        ]);

        return response()->json(['message' => 'Video deleted from YouTube.']);
    }

    // ---------------------------------------------------------- internals

    private function findForTenant(Request $request, string $id): YoutubePost
    {
        $post = YoutubePost::with(['creator', 'account'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->find($id);

        if (! $post) {
            throw new HttpException(404, 'Post not found.');
        }

        return $post;
    }

    /** draft | schedule | now — decided by the same two fields Instagram reads. */
    private function mode(Request $request): string
    {
        if ($request->boolean('publish_now')) {
            return 'now';
        }

        return filled($request->input('scheduled_at')) ? 'schedule' : 'draft';
    }

    /**
     * Validate the composer's payload.
     *
     * A draft may be half-written — no file, no title — because composing
     * usually starts somewhere; the moment it is sent or scheduled the rules
     * YouTube would apply after the upload are applied here, before it.
     */
    private function validatePayload(Request $request, int $tenantId): array
    {
        $isDraft = $this->mode($request) === 'draft';

        $data = $request->validate([
            'title' => [$isDraft ? 'nullable' : 'required', 'string', 'max:'.self::MAX_TITLE, 'not_regex:/[<>]/'],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION, 'not_regex:/[<>]/'],
            'tags' => ['nullable', 'array', 'max:60'],
            'tags.*' => ['string', 'max:100'],
            'category_id' => ['nullable', 'string', 'regex:/^\d{1,4}$/'],
            'privacy_status' => ['required', Rule::in(YoutubePost::PRIVACY)],
            'made_for_kids' => ['sometimes', 'boolean'],
            'notify_subscribers' => ['sometimes', 'boolean'],
            'video_path' => [$isDraft ? 'nullable' : 'required', 'string', 'max:1024'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ], [
            'title.not_regex' => 'YouTube does not accept < or > in a title.',
            'description.not_regex' => 'YouTube does not accept < or > in a description.',
        ]);

        $this->assertTagsFit($data['tags'] ?? []);

        if (! empty($data['video_path'])) {
            $this->assertOwnFile($data['video_path'], $tenantId);
        }

        return $data;
    }

    /** YouTube counts the tags together: 500 characters, a tag with a space counting its quotes. */
    private function assertTagsFit(array $tags): void
    {
        $length = collect($tags)->sum(fn ($tag) => mb_strlen((string) $tag) + (str_contains((string) $tag, ' ') ? 2 : 0))
            + max(0, count($tags) - 1);

        if ($length > self::MAX_TAGS_CHARS) {
            throw ValidationException::withMessages(['tags' => 'YouTube allows up to 500 characters of tags in total.']);
        }
    }

    /**
     * The path names a file this workspace uploaded.
     *
     * The upload job reads whatever this column says, with the platform's
     * credentials to the disk — so a path from the request is checked to sit
     * under this workspace's own folder, or a crafted one would upload another
     * workspace's video to this channel.
     */
    private function assertOwnFile(string $path, int $tenantId): void
    {
        $prefix = $this->directory($tenantId).'/';

        if (! str_starts_with($path, $prefix) || str_contains($path, '..') || ! MediaStorage::disk()->exists($path)) {
            throw ValidationException::withMessages(['video_path' => 'Upload the video file again.']);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $data, string $mode): array
    {
        $path = $data['video_path'] ?? null;
        $disk = MediaStorage::disk();

        return [
            'status' => $mode === 'draft' ? PostStatus::Draft : PostStatus::Queued,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'tags' => array_values(array_filter(array_map('trim', $data['tags'] ?? []))) ?: null,
            'category_id' => $data['category_id'] ?? null,
            'privacy_status' => $data['privacy_status'],
            'made_for_kids' => (bool) ($data['made_for_kids'] ?? false),
            'notify_subscribers' => (bool) ($data['notify_subscribers'] ?? true),
            // Size and type read from the disk, never from the request: they
            // are what the upload declares to YouTube.
            'video_path' => $path,
            'video_name' => $path ? basename($path) : null,
            'video_size' => $path ? (int) $disk->size($path) : null,
            'video_mime' => $path ? ($disk->mimeType($path) ?: 'video/mp4') : null,
            // A draft keeps no date: the API reads a date as "this is a
            // schedule", same rule as Instagram's composer.
            'scheduled_at' => $mode === 'schedule' ? Carbon::parse($data['scheduled_at']) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function videoFile(string $path): array
    {
        $disk = MediaStorage::disk();

        return [
            'path' => $path,
            'name' => basename($path),
            'size' => (int) $disk->size($path),
            'mime' => $disk->mimeType($path) ?: null,
            // For the composer's preview and the draft tile only.
            'preview_url' => MediaStorage::signedUrl($path, now()->addDay()),
        ];
    }

    private function directory(int $tenantId): string
    {
        return 'youtube/'.$tenantId;
    }

    private function uploadsPlaylist(YoutubeAccount $account, $client): ?string
    {
        $playlist = $client->channel()['contentDetails']['relatedPlaylists']['uploads'] ?? null;

        if ($playlist) {
            $account->update(['uploads_playlist_id' => $playlist]);
        }

        return $playlist;
    }
}
