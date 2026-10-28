<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

function youtubeThread(string $id, string $author, array $replies = [], array $snippet = []): array
{
    return [
        'id' => $id,
        'snippet' => array_merge([
            'videoId' => 'v1',
            'canReply' => true,
            'totalReplyCount' => count($replies),
            'topLevelComment' => [
                'id' => $id,
                'snippet' => [
                    'textOriginal' => "Comentário {$id}",
                    'authorDisplayName' => $author === YoutubeFixtures::CHANNEL_ID ? 'Loja Aurora' : 'Cliente',
                    'authorChannelId' => ['value' => $author],
                    'likeCount' => 2,
                    'publishedAt' => '2026-10-20T10:00:00Z',
                ],
            ],
        ], $snippet),
        'replies' => ['comments' => $replies],
    ];
}

function youtubeReply(string $id, string $author, string $at): array
{
    return ['id' => $id, 'snippet' => [
        'textOriginal' => "Resposta {$id}",
        'authorChannelId' => ['value' => $author],
        'publishedAt' => $at,
    ]];
}

test('the Comments section reads every thread on the channel, marking what the channel answered', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubeFixtures::fake([
        'youtube/v3/commentThreads' => Http::response([
            'items' => [
                youtubeThread('c1', 'UCcustomer', [
                    youtubeReply('r2', YoutubeFixtures::CHANNEL_ID, '2026-10-21T10:00:00Z'),
                    youtubeReply('r1', 'UCcustomer', '2026-10-20T11:00:00Z'),
                ]),
                youtubeThread('c2', 'UCcustomer'),
            ],
            'nextPageToken' => 'P2',
        ]),
        'youtube/v3/videos' => Http::response(['items' => [YoutubeFixtures::video('v1')]]),
    ]);

    $response = $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/comments")->assertOk();

    expect($response->json('data.0.replied'))->toBeTrue()
        ->and($response->json('data.0.replies.0.id'))->toBe('r1')
        ->and($response->json('data.0.replies.1.from_account'))->toBeTrue()
        ->and($response->json('data.1.replied'))->toBeFalse()
        ->and($response->json('videos.0.title'))->toBe('Video v1')
        ->and($response->json('next_cursor'))->toBe('P2');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'allThreadsRelatedToChannelId='.YoutubeFixtures::CHANNEL_ID)
        && str_contains($request->url(), 'moderationStatus=published'));
});

test('held comments are their own list, and a video narrows it', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubeFixtures::fake([
        'youtube/v3/commentThreads' => Http::response(['items' => []]),
        'youtube/v3/videos' => Http::response(['items' => []]),
    ]);

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/comments?status=heldForReview&video=v9")->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'moderationStatus=heldForReview')
        && str_contains($request->url(), 'videoId=v9')
        && ! str_contains($request->url(), 'allThreadsRelatedToChannelId'));
});

test('replying posts as the channel and returns the reply to show', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubeFixtures::fake([
        'youtube/v3/comments' => fn ($request) => Http::response([
            'id' => 'new-reply',
            'snippet' => [
                'textOriginal' => $request['snippet']['textOriginal'],
                'authorChannelId' => ['value' => YoutubeFixtures::CHANNEL_ID],
                'parentId' => $request['snippet']['parentId'],
            ],
        ]),
    ]);

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/comments/c1/replies", ['message' => 'Obrigado!'])
        ->assertCreated()
        ->assertJsonPath('data.id', 'new-reply')
        ->assertJsonPath('data.from_account', true);
});

test('removing someone else\'s comment rejects it, and can ban its author', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    YoutubeFixtures::fake(['youtube/v3/comments/setModerationStatus' => Http::response('', 204)]);

    $this->actingAs($user)->patchJson("/api/youtube/accounts/{$account->id}/comments/c1", [
        'status' => 'rejected',
        'ban_author' => true,
    ])->assertOk();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), 'moderationStatus=rejected')
        && str_contains($request->url(), 'banAuthor=true'));
});

test('banning is never sent with anything but a removal', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    YoutubeFixtures::fake(['youtube/v3/comments/setModerationStatus' => Http::response('', 204)]);

    $this->actingAs($user)->patchJson("/api/youtube/accounts/{$account->id}/comments/c1", [
        'status' => 'published',
        'ban_author' => true,
    ])->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'moderationStatus=published')
        && ! str_contains($request->url(), 'banAuthor'));
});

test('moderation needs the moderation permission', function () {
    $user = YoutubeFixtures::user(['youtube-posts.view']);
    $account = YoutubeFixtures::account($user);

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/comments")->assertForbidden();
    $this->actingAs($user)->deleteJson("/api/youtube/accounts/{$account->id}/comments/c1")->assertForbidden();
});

test('comments turned off on a video read as that, not as an outage', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubeFixtures::fake([
        'youtube/v3/commentThreads' => Http::response(['error' => [
            'code' => 403,
            'message' => 'The video identified by the <code>videoId</code> parameter has disabled comments.',
            'errors' => [['reason' => 'commentsDisabled']],
        ]], 403),
    ]);

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/videos/v1/comments")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'youtube_comments_disabled');
});
