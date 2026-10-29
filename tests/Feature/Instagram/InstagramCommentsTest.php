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

// ------------------------------------------------------------------ Comments section

test("the comments section lists every post's comments in one call, newest first", function () {
    Http::fake(fn () => Http::response([
        'data' => [
            [
                'id' => 'post-new',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example.com/new.jpg',
                'caption' => 'Novidade',
                'comments_count' => 2,
                'comments' => ['data' => [
                    ['id' => 'c-1', 'text' => 'Quanto custa?', 'username' => 'cliente', 'timestamp' => '2026-10-27T10:00:00+0000'],
                    [
                        'id' => 'c-2',
                        'text' => 'Tem em azul?',
                        'username' => 'outra',
                        'timestamp' => '2026-10-28T09:00:00+0000',
                        // The account's own answer — matched on the username,
                        // in whatever case Instagram spells it.
                        'replies' => ['data' => [
                            ['id' => 'r-1', 'text' => 'Tem sim!', 'username' => 'Loja.Oficial', 'timestamp' => '2026-10-28T09:05:00+0000'],
                        ]],
                    ],
                ]],
            ],
            // Nobody commented: nothing to moderate, so not listed.
            ['id' => 'post-quiet', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example.com/q.jpg'],
            [
                'id' => 'post-video',
                'media_type' => 'VIDEO',
                'thumbnail_url' => 'https://cdn.example.com/cover.jpg',
                'comments' => ['data' => [
                    ['id' => 'c-3', 'text' => 'Lindo', 'username' => 'fã', 'timestamp' => '2026-10-28T12:00:00+0000', 'hidden' => true],
                ]],
            ],
        ],
        'paging' => ['cursors' => ['after' => 'OLDER'], 'next' => 'https://graph.instagram.com/next'],
    ]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $response = $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.id', 'c-3')
        ->assertJsonPath('data.0.hidden', true)
        ->assertJsonPath('data.1.id', 'c-2')
        ->assertJsonPath('data.1.replied', true)
        ->assertJsonPath('data.1.replies.0.from_account', true)
        ->assertJsonPath('data.2.id', 'c-1')
        ->assertJsonPath('data.2.replied', false)
        ->assertJsonPath('data.2.media_id', 'post-new')
        ->assertJsonPath('next_cursor', 'OLDER')
        ->assertJsonPath('has_more', true);

    expect(collect($response->json('posts'))->pluck('id')->all())->toBe(['post-new', 'post-video']);
    expect($response->json('posts.1.thumbnail'))->toBe('https://cdn.example.com/cover.jpg');
    expect($response->json('posts.0'))->not->toHaveKey('comments');

    // One Graph call, comments riding on the media call.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/media')
        && str_contains($request->data()['fields'] ?? urldecode($request->url()), 'comments.limit(50)'));
});

test('the author comes from `from` when Instagram leaves `username` out', function () {
    // What production answers since Oct 2026: no top-level username at all.
    Http::fake(fn () => Http::response([
        'data' => [[
            'id' => 'post-1',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/p.jpg',
            'comments' => ['data' => [[
                'id' => 'c-1',
                'text' => 'Tem em azul?',
                'from' => ['id' => '111', 'username' => 'cliente'],
                'timestamp' => '2026-10-28T09:00:00+0000',
                'replies' => ['data' => [
                    ['id' => 'r-1', 'text' => 'Tem sim!', 'from' => ['id' => '222', 'username' => 'loja.oficial'], 'timestamp' => '2026-10-28T09:05:00+0000'],
                ]],
            ]]],
        ]],
    ]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.username', 'cliente')
        ->assertJsonPath('data.0.replies.0.username', 'loja.oficial')
        // Without the name, the account's own answer reads as someone else's.
        ->assertJsonPath('data.0.replies.0.from_account', true)
        ->assertJsonPath('data.0.replied', true);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), ',from,'));
});

test("a post's comments carry the author from `from` too", function () {
    Http::fake(fn () => Http::response([
        'data' => [['id' => '1789', 'text' => 'Quanto custa?', 'from' => ['id' => '111', 'username' => 'cliente']]],
    ]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/media/17999/comments")
        ->assertOk()
        ->assertJsonPath('data.0.username', 'cliente');
});

test('the comments section pages back through older posts', function () {
    Http::fake(fn () => Http::response(['data' => []]));

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/comments?after=OLDER")
        ->assertOk()
        ->assertJsonPath('has_more', false);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'after=OLDER'));
});

test('the comments section is for moderators, apart from posting', function () {
    Http::fake(fn () => Http::response(['data' => []]));

    // Posts but no moderation: the grid, never the comments section.
    $publisher = InstagramFixtures::user(['instagram-posts.view', 'instagram-posts.create']);
    $connection = InstagramFixtures::connection($publisher);

    $this->actingAs($publisher)
        ->getJson("/api/instagram/accounts/{$connection->id}/comments")
        ->assertForbidden();

    // Moderation but no posts: the account and its comments, never the grid.
    $moderator = InstagramFixtures::user(['instagram-comments.manage']);
    $own = InstagramFixtures::connection($moderator);

    $this->actingAs($moderator)->getJson('/api/instagram/accounts')->assertOk();
    $this->actingAs($moderator)->getJson("/api/instagram/accounts/{$own->id}/comments")->assertOk();
    $this->actingAs($moderator)->getJson("/api/instagram/accounts/{$own->id}/posts")->assertForbidden();
});

test("the comments section never reads an account the user was not given", function () {
    $user = InstagramFixtures::user();
    $other = InstagramFixtures::user();
    $theirs = InstagramFixtures::connection($other);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$theirs->id}/comments")
        ->assertNotFound();
});
