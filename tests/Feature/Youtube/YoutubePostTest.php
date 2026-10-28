<?php

use App\Enums\Youtube\PostStatus;
use App\Jobs\PublishYoutubePost;
use App\Models\YoutubePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

/** A file already in the workspace's upload folder, as the upload endpoint leaves it. */
function youtubeFile($user, string $name = 'lancamento.mp4'): string
{
    $path = "youtube/{$user->tenant_id}/abc123def456/{$name}";
    Storage::disk('local')->put($path, str_repeat('v', 2048));

    return $path;
}

function youtubePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Lançamento de outubro',
        'description' => 'Tudo sobre a coleção nova',
        'tags' => ['moda', 'outono'],
        'privacy_status' => 'public',
        'made_for_kids' => false,
    ], $overrides);
}

test('the upload endpoint keeps the file on the private disk under the workspace folder', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $response = $this->actingAs($user)->post("/api/youtube/accounts/{$account->id}/uploads", [
        'file' => UploadedFile::fake()->create('Lançamento Outubro.mp4', 500, 'video/mp4'),
    ])->assertCreated();

    $path = $response->json('data.path');

    expect($path)->toStartWith("youtube/{$user->tenant_id}/")
        ->and($response->json('data.preview_url'))->toContain('/storage/');
    Storage::disk('local')->assertExists($path);
});

test('anything that is not a video is refused at upload', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $this->actingAs($user)->post("/api/youtube/accounts/{$account->id}/uploads", [
        'file' => UploadedFile::fake()->image('foto.png'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('a draft may be saved with nothing but a title', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", [
        'title' => 'Ideia',
        'privacy_status' => 'public',
    ])->assertCreated()->assertJsonPath('data.status', 'draft');

    Queue::assertNothingPushed();
});

test('publishing now queues the upload and needs the publish permission', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", youtubePayload([
        'video_path' => youtubeFile($user),
        'publish_now' => true,
    ]))->assertCreated()->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(PublishYoutubePost::class);

    $drafter = YoutubeFixtures::user(['youtube-posts.view', 'youtube-posts.create']);
    $theirs = YoutubeFixtures::account($drafter);

    $this->actingAs($drafter)->postJson("/api/youtube/accounts/{$theirs->id}/posts", youtubePayload([
        'video_path' => youtubeFile($drafter),
        'publish_now' => true,
    ]))->assertForbidden();
});

test('a schedule is uploaded right away and carries its time', function () {
    $user = YoutubeFixtures::user(['youtube-posts.view', 'youtube-posts.create']);
    $account = YoutubeFixtures::account($user);
    $when = now()->addDays(2)->startOfMinute();

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", youtubePayload([
        'video_path' => youtubeFile($user),
        'scheduled_at' => $when->toIso8601String(),
    ]))->assertCreated()->assertJsonPath('data.status', 'queued');

    expect(YoutubePost::sole()->scheduled_at->equalTo($when))->toBeTrue();
    Queue::assertPushed(PublishYoutubePost::class);
});

test('a file from another workspace cannot be posted, even by its exact path', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $stranger = YoutubeFixtures::user();

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", youtubePayload([
        'video_path' => youtubeFile($stranger),
        'publish_now' => true,
    ]))->assertUnprocessable()->assertJsonValidationErrors('video_path');

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", youtubePayload([
        'video_path' => "youtube/{$user->tenant_id}/../{$stranger->tenant_id}/x.mp4",
        'publish_now' => true,
    ]))->assertUnprocessable()->assertJsonValidationErrors('video_path');
});

test('what YouTube would refuse after the upload is refused before it', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $path = youtubeFile($user);

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", youtubePayload([
        'title' => 'Promoção <imperdível>',
        'video_path' => $path,
        'publish_now' => true,
    ]))->assertJsonValidationErrors('title');

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", youtubePayload([
        'tags' => array_fill(0, 30, str_repeat('t', 20)),
        'video_path' => $path,
        'publish_now' => true,
    ]))->assertJsonValidationErrors('tags');

    $this->actingAs($user)->postJson("/api/youtube/accounts/{$account->id}/posts", youtubePayload([
        'title' => null,
        'video_path' => $path,
        'publish_now' => true,
    ]))->assertJsonValidationErrors('title');
});

test('deleting a draft gives its file back', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $path = youtubeFile($user);

    $post = YoutubePost::create([
        'tenant_id' => $user->tenant_id,
        'youtube_account_id' => $account->id,
        'status' => PostStatus::Draft,
        'privacy_status' => 'public',
        'video_path' => $path,
    ]);

    $this->actingAs($user)->deleteJson("/api/youtube/posts/{$post->id}")->assertOk();

    Storage::disk('local')->assertMissing($path);
    expect(YoutubePost::count())->toBe(0);
});

test('the feed puts our pending rows above the uploads, with scheduled videos first', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubePost::create([
        'tenant_id' => $user->tenant_id,
        'youtube_account_id' => $account->id,
        'status' => PostStatus::Draft,
        'title' => 'Rascunho',
        'privacy_status' => 'public',
    ]);

    YoutubeFixtures::fake([
        'youtube/v3/playlistItems' => Http::response([
            'items' => [
                ['contentDetails' => ['videoId' => 'live1']],
                ['contentDetails' => ['videoId' => 'soon1']],
            ],
            'nextPageToken' => 'NEXT',
        ]),
        'youtube/v3/videos' => Http::response(['items' => [
            YoutubeFixtures::video('soon1', ['privacyStatus' => 'private', 'publishAt' => now()->addDay()->toIso8601ZuluString()]),
            YoutubeFixtures::video('live1'),
        ]]),
    ]);

    $response = $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/posts")->assertOk();

    expect($response->json('pending.0.title'))->toBe('Rascunho')
        ->and($response->json('published.0.id'))->toBe('soon1')
        ->and($response->json('published.0.is_scheduled'))->toBeTrue()
        ->and($response->json('published.1.id'))->toBe('live1')
        ->and($response->json('published.1.view_count'))->toBe(120)
        ->and($response->json('published.1.duration_seconds'))->toBe(90)
        ->and($response->json('next_cursor'))->toBe('NEXT');
});

test('a channel Google stopped honouring answers with a reconnect code', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user, [
        'credentials' => ['access_token' => 'old', 'refresh_token' => 'dead', 'token_expires_at' => now()->subMinute()->toIso8601String()],
    ]);
    YoutubeFixtures::configureClient();

    YoutubeFixtures::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
    ]);

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/posts")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'youtube_reconnect_required');

    expect($account->fresh()->needsReconnect())->toBeTrue();
});

test('editing a live video sends whole parts, keeping what was not changed', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubeFixtures::fake([
        'youtube/v3/videos' => fn ($request) => $request->method() === 'PUT'
            ? Http::response(['id' => 'live1'] + $request->data())
            : Http::response(['items' => [YoutubeFixtures::video('live1', ['license' => 'creativeCommon'], ['categoryId' => '10'])]]),
    ]);

    $this->actingAs($user)->patchJson("/api/youtube/accounts/{$account->id}/videos/live1", [
        'title' => 'Título novo',
        'description' => 'Nova descrição',
        'privacy_status' => 'unlisted',
    ])->assertOk()->assertJsonPath('data.title', 'Título novo');

    Http::assertSent(function ($request) {
        if ($request->method() !== 'PUT') {
            return false;
        }

        return $request['snippet']['categoryId'] === '10'
            && $request['status']['privacyStatus'] === 'unlisted'
            && $request['status']['license'] === 'creativeCommon';
    });
});

test('scheduling a live video makes it private until then', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubeFixtures::fake([
        'youtube/v3/videos' => fn ($request) => $request->method() === 'PUT'
            ? Http::response(['id' => 'v1'] + $request->data())
            : Http::response(['items' => [YoutubeFixtures::video('v1', ['privacyStatus' => 'private'])]]),
    ]);

    $this->actingAs($user)->patchJson("/api/youtube/accounts/{$account->id}/videos/v1", [
        'title' => 'Estreia',
        'privacy_status' => 'public',
        'publish_at' => now()->addDays(3)->toIso8601String(),
    ])->assertOk()->assertJsonPath('data.is_scheduled', true);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['status']['privacyStatus'] === 'private'
        && isset($request['status']['publishAt']));
});

test('a video of another channel cannot be edited through this one', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubeFixtures::fake([
        'youtube/v3/videos' => Http::response(['items' => [YoutubeFixtures::video('x', [], ['channelId' => 'UCsomeoneelse'])]]),
    ]);

    $this->actingAs($user)->patchJson("/api/youtube/accounts/{$account->id}/videos/x", [
        'title' => 'Hijack',
        'privacy_status' => 'public',
    ])->assertNotFound();
});

test('deleting a live video needs the delete permission and removes it at YouTube', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    YoutubeFixtures::fake(['youtube/v3/videos' => Http::response('', 204)]);

    $this->actingAs($user)->deleteJson("/api/youtube/accounts/{$account->id}/videos/v1")->assertOk();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'id=v1'));

    $viewer = YoutubeFixtures::user(['youtube-posts.view']);
    $theirs = YoutubeFixtures::account($viewer);
    $this->actingAs($viewer)->deleteJson("/api/youtube/accounts/{$theirs->id}/videos/v1")->assertForbidden();
});

test('when YouTube cannot be read, the workspace\'s own drafts still show, with the reason', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    YoutubePost::create([
        'tenant_id' => $user->tenant_id,
        'youtube_account_id' => $account->id,
        'status' => PostStatus::Draft,
        'title' => 'Rascunho',
        'privacy_status' => 'public',
    ]);

    YoutubeFixtures::fake([
        'youtube/v3/playlistItems' => Http::response(['error' => [
            'code' => 403,
            'message' => 'The request cannot be completed because you have exceeded your quota.',
            'errors' => [['reason' => 'quotaExceeded']],
        ]], 403),
    ]);

    $this->actingAs($user)->getJson("/api/youtube/accounts/{$account->id}/posts")
        ->assertOk()
        ->assertJsonPath('pending.0.title', 'Rascunho')
        ->assertJsonPath('published', [])
        ->assertJsonFragment(['youtube_error' => 'O limite diário de uso do YouTube nesta plataforma acabou. Ele é renovado à meia-noite (horário do Pacífico) — tente novamente depois disso.']);
});
