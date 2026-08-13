<?php

use App\Enums\Instagram\PostStatus;
use App\Jobs\PublishInstagramPost;
use App\Models\InstagramPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InstagramFixtures;

uses(RefreshDatabase::class);

test('a post with no date is a draft and queues nothing', function () {
    Queue::fake();

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $response = $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::imagePayload())
        ->assertCreated();

    expect($response->json('data.status'))->toBe('draft');
    Queue::assertNothingPushed();
});

test('a post with a date is scheduled, and the scheduler is what fires it', function () {
    Queue::fake();

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::imagePayload([
            'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'scheduled');

    // Not dispatched now — a schedule that queued immediately would post at
    // once and only *look* scheduled.
    Queue::assertNothingPushed();

    $post = InstagramPost::firstOrFail();
    expect($post->scheduled_at)->not->toBeNull();

    // Due posts are picked up by the minute hand.
    $post->update(['scheduled_at' => now()->subMinute()]);
    $this->artisan('instagram:publish-scheduled')->assertSuccessful();

    Queue::assertPushed(PublishInstagramPost::class);
});

test('publishing now needs the publish permission, not just create', function () {
    Queue::fake();

    $user = InstagramFixtures::user(['instagram-posts.view', 'instagram-posts.create']);
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::imagePayload([
            'publish_now' => true,
        ]))
        ->assertForbidden();

    Queue::assertNothingPushed();
    expect(InstagramPost::count())->toBe(0);
});

test('publish now overrides a leftover date so the post cannot go out twice', function () {
    Queue::fake();

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::imagePayload([
            'publish_now' => true,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ]))
        ->assertCreated();

    $post = InstagramPost::firstOrFail();

    expect($post->scheduled_at)->toBeNull()
        ->and($post->status)->toBe(PostStatus::Draft);

    Queue::assertPushed(PublishInstagramPost::class);

    // And the scheduler must not find it a day later.
    $this->travelTo(now()->addDays(2));
    Queue::fake();
    $this->artisan('instagram:publish-scheduled')->assertSuccessful();
    Queue::assertNothingPushed();
});

test('a carousel needs between two and ten items', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", [
            'media_type' => 'carousel',
            'items' => [['url' => 'https://cdn.example.com/a.jpg', 'media_type' => 'image']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::carouselPayload(3))
        ->assertCreated();
});

test('a photo post cannot carry a video, and a reel needs one', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::imagePayload([
            'items' => [['url' => 'https://cdn.example.com/a.mp4', 'media_type' => 'video']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", [
            'media_type' => 'reels',
            'items' => [['url' => 'https://cdn.example.com/a.jpg', 'media_type' => 'image']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');
});

test('a story drops its caption rather than refusing it', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", [
            'media_type' => 'stories',
            'caption' => 'Instagram ignores this',
            'items' => [['url' => 'https://cdn.example.com/a.jpg', 'media_type' => 'image']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.caption', null);
});

test('a published post can neither be edited nor deleted here', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $post = InstagramPost::create([
        'tenant_id' => $user->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $user->id,
        'status' => PostStatus::Published,
        'media_type' => 'image',
        'ig_media_id' => '17999',
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->putJson("/api/instagram/posts/{$post->id}", InstagramFixtures::imagePayload())
        ->assertStatus(422);

    // Instagram Login exposes no delete for live media, so removing our row
    // would only hide a post that is still up.
    $this->actingAs($user)
        ->deleteJson("/api/instagram/posts/{$post->id}")
        ->assertStatus(422);

    expect(InstagramPost::whereKey($post->id)->exists())->toBeTrue();
});

test('editing a failed post clears the container it was going to reuse', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $post = InstagramPost::create([
        'tenant_id' => $user->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $user->id,
        'status' => PostStatus::Failed,
        'media_type' => 'image',
        'ig_container_id' => 'container-from-the-failed-run',
        'attempts' => 4,
        'error' => 'The submitted image is not a valid JPEG',
    ]);

    $this->actingAs($user)
        ->putJson("/api/instagram/posts/{$post->id}", InstagramFixtures::imagePayload())
        ->assertOk();

    $post->refresh();

    expect($post->ig_container_id)->toBeNull()
        ->and($post->attempts)->toBe(0)
        ->and($post->error)->toBeNull()
        ->and($post->status)->toBe(PostStatus::Draft);
});
