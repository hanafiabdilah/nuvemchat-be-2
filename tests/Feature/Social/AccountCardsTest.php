<?php

use App\Enums\Instagram\PostStatus as InstagramStatus;
use App\Enums\TikTok\PostStatus as TikTokStatus;
use App\Enums\Youtube\PostStatus as YoutubeStatus;
use App\Models\InstagramPost;
use App\Models\TikTokPost;
use App\Models\YoutubePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramFixtures;
use Tests\Support\TikTokFixtures;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

/*
 * The Posts page draws one card per account: the network's own numbers
 * (followers, posts…), fetched card by card from a cached endpoint, and ours
 * (scheduled, drafts, failed, next date), which come with the list.
 */

test('the Instagram list says what is scheduled, drafted and failed, and when the next post goes out', function () {
    Carbon::setTestNow('2026-11-19 12:00:00');

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    foreach ([
        [InstagramStatus::Scheduled, '2026-11-21 09:00:00'],
        [InstagramStatus::Scheduled, '2026-11-20 14:30:00'],
        [InstagramStatus::Draft, null],
        [InstagramStatus::Draft, null],
        [InstagramStatus::Failed, null],
        [InstagramStatus::Published, null],
    ] as [$status, $at]) {
        InstagramPost::create([
            'tenant_id' => $user->tenant_id,
            'connection_id' => $connection->id,
            'created_by' => $user->id,
            'status' => $status,
            'media_type' => 'image',
            'scheduled_at' => $at,
        ]);
    }

    $card = $this->actingAs($user->fresh())->getJson('/api/instagram/accounts')->assertOk()->json('data.0');

    expect($card['scheduled_count'])->toBe(2)
        ->and($card['draft_count'])->toBe(2)
        ->and($card['failed_count'])->toBe(1)
        ->and(Carbon::parse($card['next_scheduled_at'])->equalTo(Carbon::parse('2026-11-20 14:30:00')))->toBeTrue()
        // The list itself never calls Meta.
        ->and($card['profile'])->toBeNull();

    Http::assertNothingSent();
});

test('an Instagram card fetches its profile once an hour, with followers and following', function () {
    Http::fake([
        'graph.instagram.com/*' => Http::response([
            'id' => '17841400000000000',
            'username' => 'loja.oficial',
            'account_type' => 'BUSINESS',
            'media_count' => 312,
            'followers_count' => 12500,
            'follows_count' => 180,
        ]),
    ]);

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.followers_count', 12500)
        ->assertJsonPath('data.follows_count', 180)
        ->assertJsonPath('data.media_count', 312)
        ->assertJsonPath('data.account_type', 'BUSINESS');

    // The second card render, and the list after it, come from the cache.
    $this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/profile")->assertOk();
    $this->actingAs($user)->getJson('/api/instagram/accounts')
        ->assertJsonPath('data.0.profile.followers_count', 12500);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'follows_count')
        && str_contains(urldecode($request->url()), 'account_type'));
});

test('an Instagram card whose token lost its permissions says so, and is not cached', function () {
    Http::fake([
        'graph.instagram.com/*' => Http::response([
            'error' => ['message' => 'Application does not have permission for this action', 'type' => 'OAuthException', 'code' => 10],
        ], 400),
    ]);

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/profile")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'instagram_permission_required');

    $this->actingAs($user)->getJson('/api/instagram/accounts')->assertJsonPath('data.0.profile', null);
});

test('an agent without the account cannot read its profile', function () {
    Http::fake();

    $owner = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($owner);

    $agent = InstagramFixtures::user();
    $agent->forceFill(['tenant_id' => $owner->tenant_id])->save();

    $this->actingAs($agent->fresh())->getJson("/api/instagram/accounts/{$connection->id}/profile")->assertForbidden();

    Http::assertNothingSent();
});

test('a TikTok card fetches its profile once, and the list carries the queue', function () {
    TikTokFixtures::fake([
        '/business/get/' => TikTokFixtures::ok([
            'username' => 'lojaaurora',
            'followers_count' => 4200,
            'videos_count' => 37,
            'total_likes' => 91000,
            'is_verified' => true,
        ]),
    ]);

    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    foreach ([TikTokStatus::Scheduled, TikTokStatus::Draft, TikTokStatus::Failed, TikTokStatus::Failed] as $status) {
        TikTokPost::create([
            'tenant_id' => $user->tenant_id,
            'connection_id' => $connection->id,
            'created_by' => $user->id,
            'status' => $status,
            'media_type' => TikTokPost::MEDIA_VIDEO,
            'scheduled_at' => $status === TikTokStatus::Scheduled ? now()->addDay() : null,
        ]);
    }

    $this->actingAs($user)->getJson('/api/tiktok/accounts')
        ->assertOk()
        ->assertJsonPath('data.0.scheduled_count', 1)
        ->assertJsonPath('data.0.draft_count', 1)
        ->assertJsonPath('data.0.failed_count', 2);

    $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.followers_count', 4200)
        ->assertJsonPath('data.total_likes', 91000);
    $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/profile")->assertOk();

    Http::assertSentCount(1);
});

test('a TikTok account without the posting grant is asked to reconnect before any call', function () {
    Http::fake();

    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user, postScopes: false);

    $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/profile")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'tiktok_permission_required');

    Http::assertNothingSent();
});

test('a YouTube card fetches its numbers once, and a hidden subscriber count stays hidden', function () {
    YoutubeFixtures::fake([
        'youtube/v3/channels' => Http::response(['items' => [[
            'id' => YoutubeFixtures::CHANNEL_ID,
            'statistics' => ['hiddenSubscriberCount' => true, 'subscriberCount' => '900', 'videoCount' => '12', 'viewCount' => '4500'],
        ]]]),
    ]);

    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/statistics")
        ->assertOk()
        ->assertJsonPath('data.subscriber_count', null)
        ->assertJsonPath('data.video_count', 12)
        ->assertJsonPath('data.view_count', 4500);
    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/statistics")->assertOk();

    $this->actingAs($user)->getJson('/api/youtube/accounts')
        ->assertJsonPath('data.0.statistics.video_count', 12);

    Http::assertSentCount(1);
});

test('the YouTube list counts drafts and failures apart, and dates the next public video', function () {
    Carbon::setTestNow('2026-11-19 12:00:00');
    Http::fake();

    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    foreach ([
        [YoutubeStatus::Draft, null],
        [YoutubeStatus::Failed, null],
        [YoutubeStatus::Published, '2026-11-25 10:00:00'],
        [YoutubeStatus::Published, '2026-11-22 18:00:00'],
        // Already public: not waiting on anything.
        [YoutubeStatus::Published, '2026-11-01 10:00:00'],
    ] as [$status, $at]) {
        YoutubePost::create([
            'tenant_id' => $user->tenant_id,
            'youtube_account_id' => $account->id,
            'created_by' => $user->id,
            'status' => $status,
            'scheduled_at' => $at,
        ]);
    }

    $card = $this->actingAs($user)->getJson('/api/youtube/accounts')->assertOk()->json('data.0');

    expect($card['draft_count'])->toBe(1)
        ->and($card['failed_count'])->toBe(1)
        ->and($card['pending_count'])->toBe(2)
        ->and($card['scheduled_count'])->toBe(2)
        ->and(Carbon::parse($card['next_scheduled_at'])->equalTo(Carbon::parse('2026-11-22 18:00:00')))->toBeTrue()
        ->and($card['statistics'])->toBeNull();

    Http::assertNothingSent();
});
