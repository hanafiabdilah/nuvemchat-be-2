<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Instagram\PostStatus;
use App\Models\Connection;
use App\Models\InstagramPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramFixtures;

uses(RefreshDatabase::class);

test("another tenant's account is not found, not forbidden", function () {
    $user = InstagramFixtures::user();
    $stranger = InstagramFixtures::user();
    $theirConnection = InstagramFixtures::connection($stranger);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$theirConnection->id}/posts")
        ->assertNotFound();
});

test('an agent without access to the account cannot post as it', function () {
    $owner = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($owner);

    // Same tenant, same permissions, but no connection_user row for this
    // account — the pivot is the real boundary, not the permission.
    $agent = InstagramFixtures::user();
    $agent->forceFill(['tenant_id' => $owner->tenant_id])->save();

    $this->actingAs($agent->fresh())
        ->getJson("/api/instagram/accounts/{$connection->id}/posts")
        ->assertForbidden();

    $this->actingAs($agent->fresh())
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::imagePayload())
        ->assertForbidden();
});

test('losing access to the account loses access to its posts', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $post = InstagramPost::create([
        'tenant_id' => $user->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $user->id,
        'status' => PostStatus::Draft,
        'media_type' => 'image',
    ]);

    $this->actingAs($user)->getJson("/api/instagram/posts/{$post->id}")->assertOk();

    $user->connections()->detach($connection->id);

    $this->actingAs($user->fresh())
        ->getJson("/api/instagram/posts/{$post->id}")
        ->assertForbidden();
});

test('a non-Instagram connection is not an Instagram account', function () {
    $user = InstagramFixtures::user();

    $telegram = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::Telegram,
        'name' => 'Telegram bot',
        'color' => '#229ED9',
        'status' => ConnectionStatus::Active,
        'credentials' => ['bot_token' => 'tg-token'],
    ]);
    $user->connections()->syncWithoutDetaching([$telegram->id]);

    $this->actingAs($user->fresh())
        ->getJson("/api/instagram/accounts/{$telegram->id}/posts")
        ->assertNotFound();
});

test('the account list shows only connected Instagram accounts the user can reach', function () {
    $user = InstagramFixtures::user();
    $active = InstagramFixtures::connection($user, ['name' => 'Ativa']);

    // Connected but never finished OAuth: no usable token, so a card for it
    // would only fail when opened.
    InstagramFixtures::connection($user, ['name' => 'Pendente', 'status' => ConnectionStatus::Pending]);

    $response = $this->actingAs($user->fresh())
        ->getJson('/api/instagram/accounts')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.connection_id'))->toBe((string) $active->id)
        ->and($response->json('data.0.username'))->toBe('loja.oficial');
});

test('the account list counts what is still waiting to go out', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    foreach ([PostStatus::Scheduled, PostStatus::Scheduled, PostStatus::Published, PostStatus::Draft] as $status) {
        InstagramPost::create([
            'tenant_id' => $user->tenant_id,
            'connection_id' => $connection->id,
            'created_by' => $user->id,
            'status' => $status,
            'media_type' => 'image',
        ]);
    }

    $this->actingAs($user->fresh())
        ->getJson('/api/instagram/accounts')
        ->assertOk()
        // Scheduled and publishing only — a draft has no date and is not
        // "waiting", and a published post is done.
        ->assertJsonPath('data.0.scheduled_count', 2);
});

test('stories are fetched separately, because the media edge never returns them', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/stories')) {
            return Http::response(['data' => [['id' => 'story-1', 'media_type' => 'IMAGE']]]);
        }

        return Http::response(['data' => [['id' => 'ig-1', 'media_type' => 'IMAGE']]]);
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/posts")
        ->assertOk()
        ->assertJsonPath('stories.0.id', 'story-1')
        ->assertJsonPath('published.0.id', 'ig-1');
});

test('paging does not re-fetch the stories strip', function () {
    Http::fake(fn () => Http::response(['data' => []]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/posts?after=CURSOR")
        ->assertOk()
        ->assertJsonPath('stories', []);

    // The strip belongs to the first screen; asking for it on every "Load more"
    // would be a Graph call per page for a list that has not changed.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/stories'));
});

test('a stories failure does not take the grid down with it', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/stories')) {
            return Http::response(['error' => ['message' => 'Something went wrong', 'code' => 1]], 500);
        }

        return Http::response(['data' => [['id' => 'ig-1', 'media_type' => 'IMAGE']]]);
    });

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/posts")
        ->assertOk()
        ->assertJsonPath('stories', [])
        ->assertJsonPath('published.0.id', 'ig-1');
});

test('the grid returns what is waiting alongside what is live', function () {
    Http::fake(fn () => Http::response([
        'data' => [['id' => 'ig-1', 'media_type' => 'IMAGE', 'permalink' => 'https://instagram.com/p/1']],
        'paging' => ['cursors' => ['after' => 'NEXT']],
    ]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/posts", InstagramFixtures::imagePayload([
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ]))
        ->assertCreated();

    $this->actingAs($user->fresh())
        ->getJson("/api/instagram/accounts/{$connection->id}/posts")
        ->assertOk()
        ->assertJsonPath('pending.0.status', 'scheduled')
        ->assertJsonPath('published.0.id', 'ig-1')
        ->assertJsonPath('next_cursor', 'NEXT');
});
