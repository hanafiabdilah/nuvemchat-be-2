<?php

namespace App\Services\Instagram;

use App\Enums\Instagram\PostStatus;
use App\Exceptions\InstagramApiException;
use App\Models\InstagramPost;
use App\Models\InstagramPostItem;
use Illuminate\Support\Facades\Log;

/**
 * Drives Meta's three-step publish, one step per call.
 *
 * The steps are create-container → wait for Meta to fetch and transcode →
 * publish. The middle one is the awkward part: a reel can take minutes, and a
 * queue worker that sleeps through it is a worker doing nothing while every
 * other tenant's post waits behind it. So this is written to be re-entered —
 * each call advances as far as it can and reports whether it is finished, and
 * the job re-queues itself for the next pass.
 *
 * Everything already done at Meta is recorded before the call returns
 * (`ig_container_id` on the post and on each carousel child), so a second pass
 * never rebuilds a container that exists. That matters beyond tidiness: a
 * duplicated parent container is a duplicated post.
 */
class InstagramPostPublisher
{
    /**
     * How many passes a post may take before we stop.
     *
     * Sized against the container's own 24h expiry rather than any transcode:
     * with the job's backoff this is comfortably over an hour, which is far
     * longer than Meta has ever needed, and short enough that a post wedged on
     * IN_PROGRESS surfaces as failed the same day instead of never.
     */
    public const MAX_ATTEMPTS = 60;

    public function __construct(
        private readonly InstagramGraphClientFactory $clients,
    ) {}

    /**
     * Advance the post by one step.
     *
     * @return bool true when the post is live, false when it needs another pass
     */
    public function attempt(InstagramPost $post): bool
    {
        $connection = $post->connectionModel();

        if (! $connection) {
            throw new InstagramApiException('The Instagram connection for this post no longer exists.', httpStatus: 422);
        }

        $client = $this->clients->for($connection);

        $post->increment('attempts');

        if ($post->attempts > self::MAX_ATTEMPTS) {
            throw new InstagramApiException('Instagram did not finish processing this post in time. Please try again.', httpStatus: 422);
        }

        // Step 1 — build the container(s) if this is the first pass.
        if (! $post->ig_container_id) {
            $post->update([
                'ig_container_id' => $this->createParentContainer($post, $client),
            ]);
        }

        // Step 2 — wait for Meta, whatever the media is.
        //
        // This used to skip the check for photos, on the assumption that an
        // image container is ready as soon as it is created. It is not: Meta
        // does not receive the image, it receives a URL and goes off to
        // download it, so even a small JPEG spends a moment IN_PROGRESS. That
        // assumption is what produced "A mídia não está pronta para ser
        // publicada" on almost every photo — we were publishing a container
        // Meta had not finished fetching. Usually the very first check already
        // says FINISHED, so the happy path is still a single pass.
        if (! $this->containerIsReady($post, $client)) {
            return false;
        }

        // Step 3 — publish, then read back what Instagram actually created.
        $published = $client->publishContainer($post->ig_container_id);
        $mediaId = $published['id'] ?? null;

        if (! $mediaId) {
            throw new InstagramApiException('Instagram accepted the post but returned no media id.');
        }

        $post->update([
            'status' => PostStatus::Published,
            'ig_media_id' => $mediaId,
            'published_at' => now(),
            'permalink' => $this->permalinkFor($mediaId, $client),
            'error' => null,
        ]);

        return true;
    }

    /**
     * Create the container that will be published.
     *
     * For a carousel this also creates the children first — Meta wants their
     * ids in the parent's `children` parameter, so there is no way to do it in
     * one call.
     */
    private function createParentContainer(InstagramPost $post, InstagramGraphClient $client): string
    {
        $params = [];

        if ($post->media_type->isCarousel()) {
            $params['media_type'] = 'CAROUSEL';
            $params['children'] = implode(',', $this->carouselChildIds($post, $client));
        } else {
            $item = $post->items()->first();

            if (! $item) {
                throw new InstagramApiException('This post has no media to publish.', httpStatus: 422);
            }

            if ($type = $post->media_type->containerMediaType()) {
                $params['media_type'] = $type;
            }

            $params[$item->isVideo() ? 'video_url' : 'image_url'] = $item->url;
        }

        if ($post->media_type->supportsCaption() && filled($post->caption)) {
            $params['caption'] = $post->caption;
        }

        $container = $client->createContainer($params);

        if (! isset($container['id'])) {
            throw new InstagramApiException('Instagram did not return a media container.');
        }

        return (string) $container['id'];
    }

    /**
     * Container ids for every carousel child, creating the ones we do not have.
     *
     * Children already created on an earlier pass are reused rather than
     * rebuilt: each one is a separate upload for Meta to fetch, and rebuilding
     * them on a retry would multiply the bandwidth for no gain.
     */
    private function carouselChildIds(InstagramPost $post, InstagramGraphClient $client): array
    {
        return $post->items->map(function (InstagramPostItem $item) use ($client) {
            if ($item->ig_container_id) {
                return $item->ig_container_id;
            }

            $params = [
                'is_carousel_item' => 'true',
                $item->isVideo() ? 'video_url' : 'image_url' => $item->url,
            ];

            if ($item->isVideo()) {
                $params['media_type'] = 'VIDEO';
            }

            $child = $client->createContainer($params);

            if (! isset($child['id'])) {
                throw new InstagramApiException('Instagram did not return a container for one of the carousel items.');
            }

            $item->update(['ig_container_id' => (string) $child['id']]);

            return (string) $child['id'];
        })->all();
    }

    /**
     * Whether Meta has finished with the container.
     *
     * ERROR and EXPIRED are terminal and are raised with Meta's own explanation.
     * PUBLISHED is treated as ready rather than as an error: it means a previous
     * pass published this container and lost the response, and letting it fall
     * through to publishContainer() (which will refuse) is how we find out.
     */
    private function containerIsReady(InstagramPost $post, InstagramGraphClient $client): bool
    {
        $status = $client->containerStatus($post->ig_container_id);
        $code = $status['status_code'] ?? 'IN_PROGRESS';

        return match ($code) {
            'FINISHED', 'PUBLISHED' => true,
            'IN_PROGRESS' => false,
            default => throw new InstagramApiException(
                $status['status'] ?: "Instagram could not process this media ({$code}).",
                httpStatus: 422,
            ),
        };
    }

    /**
     * The public URL of the new post.
     *
     * Best-effort on purpose: the post is already live by the time this runs, so
     * a hiccup reading it back must not turn a successful publish into a failed
     * one. A missing permalink costs the UI a link, nothing more.
     */
    private function permalinkFor(string $mediaId, InstagramGraphClient $client): ?string
    {
        try {
            return $client->mediaDetail($mediaId)['permalink'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Could not read back the Instagram permalink', [
                'ig_media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
