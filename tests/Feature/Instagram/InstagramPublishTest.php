<?php

use App\Enums\Instagram\PostStatus;
use App\Jobs\PublishInstagramPost;
use App\Models\InstagramPost;
use App\Services\Instagram\InstagramPostPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InstagramFixtures;

uses(RefreshDatabase::class);

/**
 * Run one pass of the publish chain, synchronously.
 *
 * Called directly rather than through the queue because the chain re-dispatches
 * itself while Meta transcodes, and the sync driver ignores delays — so a
 * dispatched job would spin through every pass in one call and hide exactly the
 * behaviour these tests are about.
 */
function publishPass(InstagramPost $post): void
{
    (new PublishInstagramPost($post->id))->handle(app(InstagramPostPublisher::class));
}

function draftPost($user, $connection, array $attributes = [], array $items = []): InstagramPost
{
    $post = InstagramPost::create(array_merge([
        'tenant_id' => $user->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $user->id,
        'status' => PostStatus::Draft,
        'media_type' => 'image',
        'caption' => 'Novidade',
    ], $attributes));

    foreach ($items ?: [['media_type' => 'image', 'url' => 'https://cdn.example.com/a.jpg']] as $i => $item) {
        $post->items()->create($item + ['position' => $i]);
    }

    return $post->load('items');
}

test('a photo whose container is ready publishes in a single pass', function () {
    Http::fake(function ($request) {
        return match (true) {
            str_contains($request->url(), 'status_code') => Http::response(['status_code' => 'FINISHED']),
            str_contains($request->url(), '/media_publish') => Http::response(['id' => 'ig-media-1']),
            str_contains($request->url(), '/media') && $request->method() === 'POST' => Http::response(['id' => 'container-1']),
            default => Http::response(['id' => 'ig-media-1', 'permalink' => 'https://instagram.com/p/abc']),
        };
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    $post = draftPost($user, $connection);

    publishPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->ig_media_id)->toBe('ig-media-1')
        ->and($post->permalink)->toBe('https://instagram.com/p/abc')
        ->and($post->published_at)->not->toBeNull();
});

test('a photo Meta is still fetching is waited for, not published early', function () {
    Queue::fake();

    Http::fake(function ($request) {
        return match (true) {
            str_contains($request->url(), 'status_code') => Http::response(['status_code' => 'IN_PROGRESS']),
            default => Http::response(['id' => 'container-1']),
        };
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    $post = draftPost($user, $connection);

    publishPass($post);

    // The regression this guards: photos used to skip the status check on the
    // assumption that an image container is ready as soon as it is created.
    // Meta downloads the image from our URL, so it is not — and publishing
    // early is what produced "the media is not ready to be published".
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
    Queue::assertPushed(PublishInstagramPost::class);
});

test('a reel waits for Meta and publishes on a later pass', function () {
    Queue::fake();

    $statusCalls = 0;

    Http::fake(function ($request) use (&$statusCalls) {
        if (str_contains($request->url(), 'status_code')) {
            $statusCalls++;

            return Http::response(['status_code' => $statusCalls === 1 ? 'IN_PROGRESS' : 'FINISHED']);
        }

        return match (true) {
            str_contains($request->url(), '/media_publish') => Http::response(['id' => 'ig-reel-1']),
            $request->method() === 'POST' => Http::response(['id' => 'container-reel']),
            default => Http::response(['id' => 'ig-reel-1', 'permalink' => 'https://instagram.com/reel/xyz']),
        };
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    $post = draftPost($user, $connection, ['media_type' => 'reels'], [
        ['media_type' => 'video', 'url' => 'https://cdn.example.com/a.mp4'],
    ]);

    // Pass one: container built, Meta still transcoding.
    publishPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Publishing)
        ->and($post->ig_container_id)->toBe('container-reel');
    Queue::assertPushed(PublishInstagramPost::class);

    // Pass two: finished, so it goes live — and does NOT build a second
    // container, which would be a second post.
    publishPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->ig_media_id)->toBe('ig-reel-1');

    // Two container calls, two status polls, the publish, and the read-back.
    Http::assertSentCount(5);
});

test('a carousel builds one child per item, then the parent', function () {
    $children = 0;

    Http::fake(function ($request) use (&$children) {
        if (str_contains($request->url(), 'status_code')) {
            return Http::response(['status_code' => 'FINISHED']);
        }

        if (str_contains($request->url(), '/media_publish')) {
            return Http::response(['id' => 'ig-carousel-1']);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/media')) {
            if (($request->data()['is_carousel_item'] ?? null) === 'true') {
                $children++;

                return Http::response(['id' => "child-{$children}"]);
            }

            return Http::response(['id' => 'container-parent']);
        }

        return Http::response(['id' => 'ig-carousel-1', 'permalink' => 'https://instagram.com/p/car']);
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    // Mixed media on purpose: a carousel holding a video is asynchronous, which
    // is the case where reusing children matters.
    $post = draftPost($user, $connection, ['media_type' => 'carousel'], [
        ['media_type' => 'image', 'url' => 'https://cdn.example.com/1.jpg'],
        ['media_type' => 'image', 'url' => 'https://cdn.example.com/2.jpg'],
        ['media_type' => 'video', 'url' => 'https://cdn.example.com/3.mp4'],
    ]);

    publishPass($post);
    $post->refresh();

    expect($children)->toBe(3)
        ->and($post->status)->toBe(PostStatus::Published)
        ->and($post->items->pluck('ig_container_id')->all())->toBe(['child-1', 'child-2', 'child-3']);

    // The parent must have been told which children to use.
    Http::assertSent(fn ($request) => ($request->data()['children'] ?? null) === 'child-1,child-2,child-3');
});

test('a carousel retry reuses the children it already uploaded', function () {
    Queue::fake();

    $childCalls = 0;

    Http::fake(function ($request) use (&$childCalls) {
        if (str_contains($request->url(), 'status_code')) {
            return Http::response(['status_code' => 'IN_PROGRESS']);
        }

        if ($request->method() === 'POST' && ($request->data()['is_carousel_item'] ?? null) === 'true') {
            $childCalls++;

            return Http::response(['id' => "child-{$childCalls}"]);
        }

        return Http::response(['id' => 'container-parent']);
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    $post = draftPost($user, $connection, ['media_type' => 'carousel'], [
        ['media_type' => 'image', 'url' => 'https://cdn.example.com/1.jpg'],
        ['media_type' => 'video', 'url' => 'https://cdn.example.com/2.mp4'],
    ]);

    publishPass($post);
    publishPass($post);
    publishPass($post);

    // Two uploads, once — not six. Re-uploading on every poll would multiply
    // the bandwidth and, for the parent, would mean a duplicate post.
    expect($childCalls)->toBe(2)
        ->and($post->fresh()->ig_container_id)->toBe('container-parent');
});

test("Meta's own words survive to the post's error field", function () {
    Http::fake(fn () => Http::response([
        'error' => ['message' => 'The submitted image is not a valid JPEG', 'code' => 9004],
    ], 400));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    $post = draftPost($user, $connection);

    publishPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Failed)
        ->and($post->error)->toBe('The submitted image is not a valid JPEG');
});

test('a container Meta gave up on fails the post instead of polling forever', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'status_code')) {
            return Http::response(['status_code' => 'ERROR', 'status' => 'The media could not be transcoded.']);
        }

        return Http::response(['id' => 'container-1']);
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    $post = draftPost($user, $connection, ['media_type' => 'video'], [
        ['media_type' => 'video', 'url' => 'https://cdn.example.com/a.mp4'],
    ]);

    publishPass($post);

    expect($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and($post->fresh()->error)->toBe('The media could not be transcoded.');
});

test('a post already being published is not claimed a second time', function () {
    Http::fake(fn () => Http::response(['id' => 'container-1']));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    // Mid-flight: claimed by a chain that has not come back yet.
    $post = draftPost($user, $connection, ['status' => PostStatus::Publishing, 'attempts' => 0]);

    publishPass($post);

    // attempts stayed at 0, so nothing touched it — a second chain building its
    // own container here would publish the post twice.
    expect($post->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});
