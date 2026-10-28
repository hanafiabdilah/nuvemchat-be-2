<?php

use App\Enums\Youtube\PostStatus;
use App\Jobs\PublishYoutubePost;
use App\Models\YoutubeAccount;
use App\Models\YoutubePost;
use App\Services\Youtube\YoutubeUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

const YT_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=SESSION';

/** One pass of the chain, run directly (the sync queue ignores delays). */
function uploadPass(YoutubePost $post): void
{
    (new PublishYoutubePost($post->id))->handle(app(YoutubeUploader::class));
}

function queuedVideo($user, YoutubeAccount $account, array $attributes = [], int $bytes = 4096): YoutubePost
{
    $path = "youtube/{$user->tenant_id}/abc/video.mp4";
    Storage::disk('local')->put($path, str_repeat('x', $bytes));

    return YoutubePost::create(array_merge([
        'tenant_id' => $user->tenant_id,
        'youtube_account_id' => $account->id,
        'status' => PostStatus::Queued,
        'title' => 'Lançamento',
        'privacy_status' => 'public',
        'video_path' => $path,
        'video_name' => 'video.mp4',
        'video_size' => $bytes,
        'video_mime' => 'video/mp4',
    ], $attributes));
}

test('a video goes up in one pass and its file is given back', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $post = queuedVideo($user, $account);

    YoutubeFixtures::fake([
        'upload_id=SESSION' => Http::response(['id' => 'yt-new', 'status' => ['uploadStatus' => 'uploaded']], 200),
        'upload/youtube/v3/videos' => Http::response('', 200, ['Location' => YT_SESSION]),
    ]);

    uploadPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->yt_video_id)->toBe('yt-new')
        ->and($post->video_path)->toBeNull()
        ->and($post->upload_url)->toBeNull();

    Storage::disk('local')->assertMissing("youtube/{$user->tenant_id}/abc/video.mp4");

    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')
        && $request['snippet']['title'] === 'Lançamento'
        && $request['status']['privacyStatus'] === 'public'
        && $request->header('X-Upload-Content-Length')[0] === '4096');
});

test('a schedule is uploaded private with publishAt, so YouTube publishes it on time', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $post = queuedVideo($user, $account, ['scheduled_at' => now()->addDays(2), 'privacy_status' => 'public']);

    YoutubeFixtures::fake([
        'upload_id=SESSION' => Http::response(['id' => 'yt-new'], 200),
        'upload/youtube/v3/videos' => Http::response('', 200, ['Location' => YT_SESSION]),
    ]);

    uploadPass($post);

    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable')
        && $request['status']['privacyStatus'] === 'private'
        && str_ends_with($request['status']['publishAt'], 'Z'));
});

test('a schedule whose time passed while queued goes out now instead of being refused', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $post = queuedVideo($user, $account, ['scheduled_at' => now()->subMinutes(5)]);

    expect(app(YoutubeUploader::class)->metadata($post)['status'])
        ->toBe(['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false]);
});

test('a big file leaves after one slice and the chain carries on from the confirmed byte', function () {
    Queue::fake();

    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    // Two chunks' worth: the first PUT is answered "keep going".
    $post = queuedVideo($user, $account, [], YoutubeUploader::CHUNK_BYTES + 100);

    $puts = 0;
    YoutubeFixtures::fake([
        'upload_id=SESSION' => function () use (&$puts) {
            $puts++;

            return $puts === 1
                ? Http::response('', 308, ['Range' => 'bytes=0-'.(YoutubeUploader::CHUNK_BYTES - 1)])
                : Http::response(['id' => 'yt-big'], 200);
        },
        'upload/youtube/v3/videos' => Http::response('', 200, ['Location' => YT_SESSION]),
    ]);

    uploadPass($post);
    $post->refresh();

    // Both chunks went in the same pass: the pass budget was not reached.
    expect($post->status)->toBe(PostStatus::Published)
        ->and($puts)->toBe(2);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && ($request->header('Content-Range')[0] ?? '') === 'bytes '.YoutubeUploader::CHUNK_BYTES.'-'.(YoutubeUploader::CHUNK_BYTES + 99).'/'.(YoutubeUploader::CHUNK_BYTES + 100));
});

test('a resumed upload asks the session where it stands instead of opening a second one', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $post = queuedVideo($user, $account, ['status' => PostStatus::Uploading, 'attempts' => 3, 'upload_url' => YT_SESSION]);

    YoutubeFixtures::fake([
        // The status query answers that every byte already landed.
        'upload_id=SESSION' => Http::response(['id' => 'yt-already'], 200),
    ]);

    uploadPass($post);

    expect($post->fresh()->yt_video_id)->toBe('yt-already');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'uploadType=resumable') && $request->method() === 'POST');
});

test('an expired session is replaced, not failed', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $post = queuedVideo($user, $account, ['status' => PostStatus::Uploading, 'attempts' => 2, 'upload_url' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=OLD']);

    YoutubeFixtures::fake([
        'upload_id=OLD' => Http::response('Not Found', 404),
        'upload_id=SESSION' => Http::response(['id' => 'yt-fresh'], 200),
        'upload/youtube/v3/videos' => Http::response('', 200, ['Location' => YT_SESSION]),
    ]);

    uploadPass($post);

    expect($post->fresh()->yt_video_id)->toBe('yt-fresh');
});

test('an expired access token is refreshed before the upload and stored', function () {
    YoutubeFixtures::configureClient();
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user, [
        'credentials' => ['access_token' => 'old', 'refresh_token' => 'r1', 'token_expires_at' => now()->subMinute()->toIso8601String()],
    ]);
    $post = queuedVideo($user, $account);

    YoutubeFixtures::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
        'upload_id=SESSION' => Http::response(['id' => 'yt-1'], 200),
        'upload/youtube/v3/videos' => Http::response('', 200, ['Location' => YT_SESSION]),
    ]);

    uploadPass($post);

    expect($account->fresh()->credentials['access_token'])->toBe('fresh')
        ->and($account->fresh()->credentials['refresh_token'])->toBe('r1');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'uploadType=resumable') && $request->hasHeader('Authorization', 'Bearer fresh'));
});

test('the platform quota running out fails the upload with words about it', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $post = queuedVideo($user, $account);

    YoutubeFixtures::fake([
        'upload/youtube/v3/videos' => Http::response(['error' => [
            'code' => 403,
            'message' => 'The request cannot be completed because you have exceeded your quota.',
            'errors' => [['reason' => 'quotaExceeded', 'domain' => 'youtube.quota']],
        ]], 403),
    ]);

    uploadPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Failed)
        ->and($post->error)->toContain('limite diário')
        ->and($post->error)->not->toContain('exceeded your quota');
});

test('a stalled upload is revived, and one that used every attempt is failed', function () {
    Queue::fake();

    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);

    $stalled = queuedVideo($user, $account, ['status' => PostStatus::Uploading, 'attempts' => 2]);
    $stalled->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

    $spent = YoutubePost::create([
        'tenant_id' => $user->tenant_id,
        'youtube_account_id' => $account->id,
        'status' => PostStatus::Uploading,
        'privacy_status' => 'public',
        'attempts' => YoutubeUploader::MAX_ATTEMPTS,
    ]);
    $spent->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

    $this->artisan('youtube:revive-uploads')->assertSuccessful();

    Queue::assertPushed(PublishYoutubePost::class, fn ($job) => $job->postId === $stalled->id);
    expect($spent->fresh()->status)->toBe(PostStatus::Failed);
});
