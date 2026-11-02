<?php

use App\Models\CommentDmAutomation;
use App\Models\CommentDmRun;
use App\Services\Instagram\CommentDm\CommentDmAutomations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramFixtures;
use Tests\Support\TikTokFixtures;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

/**
 * TikTok, YouTube and Instagram comments wait on each platform's app review
 * (config/social.php). Off, every door to them answers 403
 * `feature_unavailable` — the plan and the permissions are untouched, so the
 * same requests work again the moment a switch is turned on.
 *
 * phpunit.xml turns all three on for the rest of the suite; these tests turn
 * them off themselves.
 */
beforeEach(function () {
    Http::fake();
    config(['social.tiktok' => false, 'social.youtube' => false, 'social.instagram_comments' => false]);
});

function socialSwitchRefused($response, string $feature): void
{
    $response->assertForbidden()
        ->assertJsonPath('code', 'feature_unavailable')
        ->assertJsonPath('feature', $feature);
}

test('the dashboard is told which of the three are on', function () {
    $user = InstagramFixtures::user();

    $this->actingAs($user)->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.platform.tiktok', false)
        ->assertJsonPath('data.platform.youtube', false)
        ->assertJsonPath('data.platform.instagram_comments', false);

    config(['social.youtube' => true]);

    $this->actingAs($user)->getJson('/api/user')
        ->assertJsonPath('data.platform.youtube', true)
        ->assertJsonPath('data.platform.tiktok', false);
});

test('Instagram comments are refused while publishing stays open', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    socialSwitchRefused($this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/comments"), 'instagram_comments');
    socialSwitchRefused($this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/media/m1/comments"), 'instagram_comments');
    socialSwitchRefused($this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/comment-automations"), 'instagram_comments');
    socialSwitchRefused($this->actingAs($user)->postJson("/api/instagram/accounts/{$connection->id}/comments/c1/replies", ['message' => 'Oi']), 'instagram_comments');
    socialSwitchRefused(
        $this->actingAs($user)->patchJson("/api/social/instagram/accounts/{$connection->id}/comments/c1/marks", ['read' => true]),
        'instagram_comments',
    );

    // Posts are not part of the review.
    $this->actingAs($user)->getJson("/api/instagram/accounts/{$connection->id}/posts")->assertOk();
});

test('a saved Comment → Direct rule answers nobody while Instagram comments are off', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    CommentDmAutomation::create([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'name' => 'Link',
        'enabled' => true,
        'match_mode' => CommentDmAutomation::MATCH_KEYWORDS,
        'keywords' => ['link'],
        'dm_message' => 'Aqui está o link',
    ]);
    $comment = ['id' => 'comment-1', 'from' => ['id' => '555', 'username' => 'maria'], 'text' => 'link?'];

    expect(CommentDmAutomations::shouldConsider($connection, $comment))->toBeFalse();

    config(['social.instagram_comments' => true]);

    expect(CommentDmAutomations::shouldConsider($connection, $comment))->toBeTrue()
        // The rule itself was never touched.
        ->and(CommentDmAutomation::count())->toBe(1)
        ->and(CommentDmRun::count())->toBe(0);
});

test('TikTok management, the TikTok channel and its OAuth are refused', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    socialSwitchRefused($this->actingAs($user)->getJson('/api/tiktok/accounts'), 'tiktok');
    socialSwitchRefused($this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/comments"), 'tiktok');
    socialSwitchRefused(
        $this->actingAs($user)->patchJson("/api/social/tiktok/accounts/{$connection->id}/comments/c1/marks", ['read' => true]),
        'tiktok',
    );

    $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('connections.create', 'web'));
    $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('connections.oauth', 'web'));

    socialSwitchRefused($this->actingAs($user)->postJson('/api/connections', ['channel' => 'tiktok', 'name' => 'TikTok']), 'tiktok');
    socialSwitchRefused($this->actingAs($user)->getJson("/api/connections/{$connection->id}/oauth"), 'tiktok');

    // Other channels are not affected.
    $this->actingAs($user)->postJson('/api/connections', ['channel' => 'telegram', 'name' => 'Telegram'])->assertCreated();
});

test('YouTube management is refused, connecting a channel included', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    socialSwitchRefused($this->actingAs($user)->getJson('/api/youtube/accounts'), 'youtube');
    socialSwitchRefused($this->actingAs($user)->getJson('/api/youtube/oauth-url'), 'youtube');
    socialSwitchRefused($this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/comments"), 'youtube');
});

test('switching one on brings its routes back without touching the others', function () {
    config(['social.tiktok' => true]);
    $user = TikTokFixtures::user();
    TikTokFixtures::connection($user);

    $this->actingAs($user)->getJson('/api/tiktok/accounts')->assertOk();

    $youtubeUser = YoutubeFixtures::user();
    socialSwitchRefused($this->actingAs($youtubeUser)->getJson('/api/youtube/accounts'), 'youtube');
});
