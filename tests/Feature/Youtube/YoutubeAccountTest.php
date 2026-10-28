<?php

use App\Events\YoutubeUpdated;
use App\Models\YoutubeAccount;
use App\Services\Youtube\YoutubeConfig;
use App\Services\Youtube\YoutubeOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

/** The state the popup would carry, created the way the real button does. */
function youtubeState($user): string
{
    $url = app(YoutubeOAuth::class)->authorizeUrl((int) $user->tenant_id, (int) $user->id);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'];
}

function fakeGoogleConsent(string $scope = 'https://www.googleapis.com/auth/youtube.force-ssl', ?string $refresh = 'refresh-1'): void
{
    YoutubeFixtures::fake([
        'oauth2.googleapis.com/token' => Http::response(array_filter([
            'access_token' => 'access-1',
            'refresh_token' => $refresh,
            'expires_in' => 3599,
            'scope' => $scope,
        ])),
        'youtube/v3/channels' => Http::response(['items' => [[
            'id' => YoutubeFixtures::CHANNEL_ID,
            'snippet' => [
                'title' => 'Loja Aurora',
                'customUrl' => '@lojaaurora',
                'thumbnails' => ['default' => ['url' => 'https://yt3.ggpht.com/a.jpg']],
            ],
            'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UUabcdefghijklmnopqrstuv']],
            'statistics' => ['subscriberCount' => '10', 'videoCount' => '2', 'viewCount' => '99'],
        ]]]),
        'oauth2.googleapis.com/revoke' => Http::response([], 200),
    ]);
}

test('the connect URL asks Google for offline access to the channel', function () {
    YoutubeFixtures::configureClient();
    $user = YoutubeFixtures::user();

    $url = $this->actingAs($user)->getJson('/api/youtube/oauth-url')->assertOk()->json('data.url');

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->toContain('access_type=offline')
        ->toContain('prompt=consent')
        ->toContain(urlencode(YoutubeConfig::SCOPES[0]))
        ->toContain(urlencode(route('oauth.youtube.callback')));
});

test('without a Google client the page is told instead of sent to a broken popup', function () {
    $user = YoutubeFixtures::user();

    $this->actingAs($user)->getJson('/api/youtube/oauth-url')
        ->assertStatus(503)
        ->assertJsonPath('code', 'youtube_not_configured');

    $this->actingAs($user)->getJson('/api/youtube/accounts')->assertOk()->assertJsonPath('can_connect', false);
});

test('connecting needs its own permission, not the posting ones', function () {
    YoutubeFixtures::configureClient();
    $user = YoutubeFixtures::user(['youtube-posts.view', 'youtube-posts.create']);

    $this->actingAs($user)->getJson('/api/youtube/oauth-url')->assertForbidden();
});

test('the callback connects the channel to the workspace that started it', function () {
    Event::fake([YoutubeUpdated::class]);
    YoutubeFixtures::configureClient();
    fakeGoogleConsent();
    $user = YoutubeFixtures::user();

    $this->get('/oauth/youtube/callback?code=abc&state='.youtubeState($user))
        ->assertRedirect()
        ->assertRedirectContains('status=success');

    $account = YoutubeAccount::sole();

    expect($account->tenant_id)->toBe($user->tenant_id)
        ->and($account->channel_id)->toBe(YoutubeFixtures::CHANNEL_ID)
        ->and($account->title)->toBe('Loja Aurora')
        ->and($account->uploads_playlist_id)->toBe('UUabcdefghijklmnopqrstuv')
        ->and($account->credentials['refresh_token'])->toBe('refresh-1')
        ->and($account->connected_by)->toBe($user->id);

    Event::assertDispatched(YoutubeUpdated::class);
});

test('a forged or reused state attaches nothing', function () {
    YoutubeFixtures::configureClient();
    fakeGoogleConsent();
    $user = YoutubeFixtures::user();

    $this->get('/oauth/youtube/callback?code=abc&state=made-up')->assertRedirectContains('status=error');

    $state = youtubeState($user);
    $this->get('/oauth/youtube/callback?code=abc&state='.$state)->assertRedirectContains('status=success');
    YoutubeAccount::query()->delete();

    // Single use: the same callback replayed lands nowhere.
    $this->get('/oauth/youtube/callback?code=abc&state='.$state)->assertRedirectContains('status=error');

    expect(YoutubeAccount::count())->toBe(0);
});

test('a consent with a scope unticked is refused rather than half-connected', function () {
    YoutubeFixtures::configureClient();
    fakeGoogleConsent(scope: 'https://www.googleapis.com/auth/youtube.readonly');
    $user = YoutubeFixtures::user();

    $this->get('/oauth/youtube/callback?code=abc&state='.youtubeState($user))->assertRedirectContains('status=error');

    expect(YoutubeAccount::count())->toBe(0);
});

test('reconnecting a channel updates its row and keeps the refresh token Google did not resend', function () {
    YoutubeFixtures::configureClient();
    fakeGoogleConsent(refresh: null);
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user, ['status' => YoutubeAccount::STATUS_RECONNECT]);

    $this->get('/oauth/youtube/callback?code=abc&state='.youtubeState($user))->assertRedirectContains('status=success');

    $account->refresh();

    expect(YoutubeAccount::count())->toBe(1)
        ->and($account->status)->toBe(YoutubeAccount::STATUS_ACTIVE)
        ->and($account->credentials['access_token'])->toBe('access-1')
        ->and($account->credentials['refresh_token'])->toBe('yt-refresh');
});

test('channels are listed per workspace and never cross it', function () {
    $user = YoutubeFixtures::user();
    $other = YoutubeFixtures::user();
    YoutubeFixtures::account($user);
    $foreign = YoutubeFixtures::account($other);

    $this->actingAs($user)->getJson('/api/youtube/accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['yt-access'])
        ->assertJsonPath('data.0.title', 'Loja Aurora');

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$foreign->id}/posts")->assertNotFound();
});

test('disconnecting revokes the grant, unless another workspace holds the same channel', function () {
    Event::fake([YoutubeUpdated::class]);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);

    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $this->actingAs($user)->deleteJson("/api/youtube/accounts/{$account->id}")->assertOk();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
    expect(YoutubeAccount::count())->toBe(0);

    // Same channel in two workspaces: revoking for one would cut off the other.
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);
    $first = YoutubeFixtures::account($user);
    YoutubeFixtures::account(YoutubeFixtures::user());

    $this->actingAs($user)->deleteJson("/api/youtube/accounts/{$first->id}")->assertOk();
    Http::assertNothingSent();
});
