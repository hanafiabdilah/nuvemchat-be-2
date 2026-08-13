<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramFixtures;

uses(RefreshDatabase::class);

test('comments are read straight from Instagram, never from us', function () {
    Http::fake(fn () => Http::response([
        'data' => [
            ['id' => '1789', 'text' => 'Quanto custa?', 'username' => 'cliente', 'hidden' => false],
        ],
        'paging' => ['cursors' => ['after' => 'CURSOR'], 'next' => 'https://graph.instagram.com/next'],
    ]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/media/17999/comments")
        ->assertOk()
        ->assertJsonPath('data.0.text', 'Quanto custa?')
        ->assertJsonPath('next_cursor', 'CURSOR')
        ->assertJsonPath('has_more', true);
});

test('replying, hiding and deleting reach the right endpoints', function () {
    Http::fake(fn () => Http::response(['id' => 'reply-1', 'success' => true]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/comments/1789/replies", ['message' => 'R$ 89,90'])
        ->assertCreated();

    $this->actingAs($user)
        ->patchJson("/api/instagram/accounts/{$connection->id}/comments/1789", ['hidden' => true])
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson("/api/instagram/accounts/{$connection->id}/comments/1789")
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/1789/replies') && $request->data()['message'] === 'R$ 89,90');
    Http::assertSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/1789') && ($request->data()['hide'] ?? null) === 'true');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('turning comments off is the one change a live post accepts', function () {
    Http::fake(fn () => Http::response(['success' => true]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->patchJson("/api/instagram/accounts/{$connection->id}/media/17999/comments", ['enabled' => false])
        ->assertOk();

    Http::assertSent(fn ($request) => ($request->data()['comment_enabled'] ?? null) === 'false');
});

test('reading comments comes with viewing posts, but moderating does not', function () {
    Http::fake(fn () => Http::response(['data' => []]));

    $viewer = InstagramFixtures::user(['instagram-posts.view']);
    $connection = InstagramFixtures::connection($viewer);

    $this->actingAs($viewer)
        ->getJson("/api/instagram/accounts/{$connection->id}/media/17999/comments")
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson("/api/instagram/accounts/{$connection->id}/comments/1789/replies", ['message' => 'oi'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson("/api/instagram/accounts/{$connection->id}/comments/1789")
        ->assertForbidden();
});

test("a missing scope is reported as something the user can fix", function () {
    // What Meta answers when the account was connected before publishing was
    // added to the app's permissions.
    Http::fake(fn () => Http::response([
        'error' => [
            'message' => 'Application does not have permission for this action',
            'code' => 10,
            'error_subcode' => 2534015,
        ],
    ], 403));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/media/17999/comments")
        ->assertStatus(422)
        ->assertJsonPath('code', 'instagram_permission_required');
});
