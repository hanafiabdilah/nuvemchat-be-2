<?php

use App\Models\SocialCommentMark;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramFixtures;
use Tests\Support\TikTokFixtures;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

/** One Graph `/media` page: a post and its comments, as the Comments section reads them. */
function marksInstagramMedia(array $comments): array
{
    return ['data' => [[
        'id' => 'm1',
        'caption' => 'Coleção nova',
        'media_type' => 'IMAGE',
        'media_url' => 'https://cdn.example.com/m1.jpg',
        'timestamp' => '2026-10-20T10:00:00+0000',
        'comments_count' => count($comments),
        'comments' => ['data' => $comments],
    ]]];
}

function marksInstagramComment(string $id, string $username = 'cliente'): array
{
    return ['id' => $id, 'text' => "Comentário {$id}", 'username' => $username, 'timestamp' => '2026-10-20T11:00:00+0000'];
}

function marksTikTokComment(string $id, string $videoId, string $createdAt): array
{
    return [
        'comment_id' => $id,
        'video_id' => $videoId,
        'text' => "Comentário {$id}",
        'username' => 'cliente',
        'owner' => false,
        'status' => 'PUBLIC',
        'create_time' => (string) strtotime($createdAt),
        'reply_list' => [],
    ];
}

test('a comment can be starred and marked read, and the marks come back with the list', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    Http::fake(fn () => Http::response(marksInstagramMedia([
        marksInstagramComment('c1'),
        marksInstagramComment('c2', 'loja.oficial'),
    ])));

    $this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('data.0.starred', false)
        // What the account wrote itself never asks for attention.
        ->assertJsonPath('data.1.read', true);

    $this->actingAs($user)
        ->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c1/marks", ['starred' => true])
        ->assertOk()
        ->assertJsonPath('data', ['read' => false, 'starred' => true]);

    $this->actingAs($user)
        ->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c1/marks", ['read' => true])
        ->assertOk()
        // A mark left out of the request is left alone.
        ->assertJsonPath('data', ['read' => true, 'starred' => true]);

    $this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/comments")
        ->assertJsonPath('data.0.read', true)
        ->assertJsonPath('data.0.starred', true);

    $this->actingAs($user)
        ->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c1/marks", ['read' => false, 'starred' => false])
        ->assertJsonPath('data', ['read' => false, 'starred' => false]);

    expect(SocialCommentMark::first())
        ->read_at->toBeNull()
        ->starred_at->toBeNull()
        ->read_by->toBeNull();
});

test('mark all as read marks every comment named, in two queries whatever the count', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    // One of them already has a mark, so both the update and the insert run.
    $this->actingAs($user)
        ->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c1/marks", ['starred' => true])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/api/social/instagram/accounts/{$connection->id}/comments/marks", ['ids' => ['c1', 'c2', 'c3', 'c2']])
        ->assertOk()
        ->assertJsonPath('data.marked', 3);

    expect(SocialCommentMark::count())->toBe(3)
        ->and(SocialCommentMark::whereNull('read_at')->count())->toBe(0)
        ->and(SocialCommentMark::where('comment_id', 'c1')->first()->starred_at)->not->toBeNull();
});

test('replying to or hiding a comment marks it read', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    Http::fake(fn () => Http::response(['id' => 'reply-1', 'success' => true]));

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/comments/c1/replies", ['message' => 'Obrigada!'])
        ->assertCreated();

    $this->actingAs($user)
        ->patchJson("/api/instagram/accounts/{$connection->id}/comments/c2", ['hidden' => true])
        ->assertOk();

    // Showing a comment again is not dealing with it.
    $this->actingAs($user)
        ->patchJson("/api/instagram/accounts/{$connection->id}/comments/c3", ['hidden' => false])
        ->assertOk();

    expect(SocialCommentMark::whereNotNull('read_at')->pluck('comment_id')->sort()->values()->all())->toBe(['c1', 'c2']);
});

test('one post\'s comments are read from that post, with the same marks', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    Http::fake(fn () => Http::response([
        'data' => [marksInstagramComment('c9')],
        'paging' => ['cursors' => ['after' => 'NEXT'], 'next' => 'https://graph.instagram.com/next'],
    ]));

    $this->actingAs($user)
        ->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c9/marks", ['starred' => true]);

    $this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/comments?media=m7")
        ->assertOk()
        ->assertJsonPath('data.0.id', 'c9')
        ->assertJsonPath('data.0.media_id', 'm7')
        ->assertJsonPath('data.0.starred', true)
        ->assertJsonPath('next_cursor', 'NEXT')
        ->assertJsonPath('has_more', true);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/m7/comments'));
});

test('marks belong to the workspace and are only set by who moderates the account', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $outsider = InstagramFixtures::user();
    $viewer = InstagramFixtures::user(['instagram-posts.view']);
    $viewerConnection = InstagramFixtures::connection($viewer);

    // Another workspace's account looks the same as one that does not exist.
    $this->actingAs($outsider)
        ->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c1/marks", ['read' => true])
        ->assertNotFound();

    $this->actingAs($viewer)
        ->patchJson("/api/social/instagram/accounts/{$viewerConnection->id}/comments/c1/marks", ['read' => true])
        ->assertForbidden();

    $this->actingAs($user)
        ->patchJson("/api/social/facebook/accounts/{$connection->id}/comments/c1/marks", ['read' => true])
        ->assertNotFound();

    // The same comment id in two workspaces is two marks.
    $this->actingAs($user)
        ->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c1/marks", ['starred' => true])
        ->assertOk();

    $outsiderConnection = InstagramFixtures::connection($outsider);

    Http::fake(fn () => Http::response(marksInstagramMedia([marksInstagramComment('c1')])));

    $this->actingAs($outsider)->getJson("/api/instagram/accounts/{$outsiderConnection->id}/comments")
        ->assertJsonPath('data.0.starred', false);
});

test('YouTube threads carry the marks of their top-level comment', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $this->actingAs($user)
        ->patchJson("/api/social/youtube/accounts/{$account->id}/comments/yc1/marks", ['starred' => true, 'read' => true])
        ->assertOk();

    YoutubeFixtures::fake([
        'youtube/v3/commentThreads' => Http::response(['items' => [[
            'id' => 'yc1',
            'snippet' => [
                'videoId' => 'v1',
                'canReply' => true,
                'totalReplyCount' => 0,
                'topLevelComment' => ['id' => 'yc1', 'snippet' => [
                    'textOriginal' => 'Ótimo vídeo',
                    'authorDisplayName' => 'Cliente',
                    'authorChannelId' => ['value' => 'UCcustomer'],
                    'publishedAt' => '2026-10-20T10:00:00Z',
                ]],
            ],
        ]]]),
        'youtube/v3/videos' => Http::response(['items' => []]),
    ]);

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.id', 'yc1')
        ->assertJsonPath('data.0.read', true)
        ->assertJsonPath('data.0.starred', true);
});

test('TikTok\'s all-posts view reads the latest posts that have comments, once per half minute', function () {
    Cache::flush();

    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    $video = fn (string $id, int $comments) => [
        'item_id' => $id, 'media_type' => 'VIDEO', 'caption' => "Post {$id}", 'comments' => $comments, 'create_time' => '1761700000',
    ];

    TikTokFixtures::fake([
        '/business/video/list/' => TikTokFixtures::ok(['videos' => [$video('v1', 2), $video('v2', 0), $video('v3', 1)], 'has_more' => false]),
        '/business/comment/list/' => function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return TikTokFixtures::ok(['comments' => match ($query['video_id'] ?? null) {
                'v1' => [marksTikTokComment('a', 'v1', '2026-10-20 10:00'), marksTikTokComment('b', 'v1', '2026-10-22 10:00')],
                'v3' => [marksTikTokComment('c', 'v3', '2026-10-21 10:00')],
                default => [],
            }]);
        },
    ]);

    $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/comments")
        ->assertOk()
        // Newest first, across the posts — and the post without comments is not read.
        ->assertJsonPath('data.*.id', ['b', 'c', 'a'])
        ->assertJsonPath('data.0.video_id', 'v1')
        ->assertJsonPath('posts.*.id', ['v1', 'v3'])
        ->assertJsonPath('data.0.read', false);

    Http::assertSentCount(3);

    // A mark set now shows on the next look, though TikTok is not asked again.
    $this->actingAs($user)
        ->patchJson("/api/social/tiktok/accounts/{$connection->id}/comments/b/marks", ['read' => true])
        ->assertOk();

    $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/comments")
        ->assertJsonPath('data.0.read', true);

    Http::assertSentCount(3);
});

test('a TikTok reply marks the comment read', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    TikTokFixtures::fake([
        '/business/comment/reply/create/' => TikTokFixtures::ok(['comment_id' => 'r1', 'text' => 'Obrigada', 'owner' => true]),
    ]);

    $this->actingAs($user)
        ->postJson("/api/tiktok/accounts/{$connection->id}/videos/v1/comments/c1/replies", ['text' => 'Obrigada'])
        ->assertCreated();

    expect(SocialCommentMark::where('platform', 'tiktok')->where('comment_id', 'c1')->first())
        ->not->toBeNull()
        ->read_at->not->toBeNull();
});
