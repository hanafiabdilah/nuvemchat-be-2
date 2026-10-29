<?php

use App\Enums\TikTok\PostStatus;
use App\Jobs\PublishTikTokPost;
use App\Models\Setting;
use App\Models\TikTokPost;
use App\Services\Connection\TikTok\TikTokAuthClient;
use App\Services\Connection\TikTok\TikTokConfig;
use App\Services\TikTok\TikTokPostPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TikTokFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/** A file already uploaded to the workspace's TikTok folder. */
function tiktokFile($user, string $name = 'clip.mp4'): string
{
    $path = "tiktok/{$user->tenant_id}/abc123/{$name}";
    Storage::disk('public')->put($path, 'bytes');

    return $path;
}

function tiktokPass(TikTokPost $post): void
{
    (new PublishTikTokPost($post->id))->handle(app(TikTokPostPublisher::class));
}

function tiktokDraft($user, $connection, array $attributes = [], ?string $path = null): TikTokPost
{
    $post = TikTokPost::create(array_merge([
        'tenant_id' => $user->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $user->id,
        'status' => PostStatus::Queued,
        'media_type' => 'video',
        'caption' => 'Novidade #loja',
    ], $attributes));

    $path ??= tiktokFile($user);
    $post->items()->create([
        'position' => 0,
        'media_type' => str_ends_with($path, '.mp4') ? 'video' : 'image',
        'path' => $path,
        'url' => \App\Services\Media\MediaStorage::publishedUrl($path),
    ]);

    return $post->load('items');
}

test('the account list shows the TikTok connections the user may reach, and which can post', function () {
    $user = TikTokFixtures::user();
    TikTokFixtures::connection($user);
    TikTokFixtures::connection($user, postScopes: false, overrides: ['name' => 'Conta antiga']);
    TikTokFixtures::connection(TikTokFixtures::user());

    $response = $this->actingAs($user)->getJson('/api/tiktok/accounts')->assertOk()->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('has_post_permissions', 'name')->all())
        ->toBe(['Conta antiga' => false, 'Loja no TikTok' => true]);
});

test('an account connected only for the inbox is asked to reconnect', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user, postScopes: false);

    $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/posts")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'tiktok_permission_required');

    Http::assertNothingSent();
});

test('the feed is our pending rows, then the live posts in our shape', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);
    tiktokDraft($user, $connection, ['status' => PostStatus::Draft]);

    TikTokFixtures::fake([
        '/business/video/list/' => TikTokFixtures::ok([
            'videos' => [[
                'item_id' => '7300000000000000001',
                'media_type' => 'VIDEO',
                'caption' => 'Ao vivo',
                'thumbnail_url' => 'https://p16.tiktokcdn.com/a.jpeg',
                'embed_url' => 'https://www.tiktok.com/player/v1/7300000000000000001',
                'video_views' => 1520,
                'likes' => 80,
                'comments' => 4,
                'create_time' => '1761700000',
            ]],
            'cursor' => 1761699000000,
            'has_more' => true,
        ]),
    ]);

    $response = $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/posts")->assertOk();

    expect($response->json('pending.0.status'))->toBe('draft')
        ->and($response->json('published.0.id'))->toBe('7300000000000000001')
        ->and($response->json('published.0.view_count'))->toBe(1520)
        ->and($response->json('published.0.created_at'))->not->toBeNull()
        ->and($response->json('next_cursor'))->toBe('1761699000000')
        ->and($response->json('has_more'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '"video_views"')
        && $request->hasHeader('Access-Token', 'tt-token'));
});

test('when TikTok cannot be read, our drafts still come back with the reason', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);
    tiktokDraft($user, $connection, ['status' => PostStatus::Draft]);

    TikTokFixtures::fake(['/business/video/list/' => TikTokFixtures::refused(40100, 'Too many requests')]);

    $this->actingAs($user)->getJson("/api/tiktok/accounts/{$connection->id}/posts")
        ->assertOk()
        ->assertJsonPath('pending.0.status', 'draft')
        ->assertJsonPath('published', [])
        ->assertJsonPath('tiktok_error', 'O TikTok limitou as requisições por agora. Aguarde um minuto e tente de novo.');
});

test('a photo is re-encoded to JPEG on the published disk, under the workspace folder', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    $response = $this->actingAs($user)->post("/api/tiktok/accounts/{$connection->id}/uploads", [
        'file' => UploadedFile::fake()->image('Foto Produto.png', 2400, 1200),
    ])->assertCreated();

    $path = $response->json('data.path');

    expect($path)->toStartWith("tiktok/{$user->tenant_id}/")->toEndWith('.jpg');
    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
    expect([$width, $height])->toBe([1920, 960]);
});

test('posting now queues the publish and needs the publish permission', function () {
    Queue::fake();
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);

    $this->actingAs($user)->postJson("/api/tiktok/accounts/{$connection->id}/posts", [
        'media_type' => 'video',
        'caption' => 'Lançamento',
        'items' => [['path' => tiktokFile($user), 'media_type' => 'image']],
        'publish_now' => true,
    ])->assertCreated()->assertJsonPath('data.status', 'queued')
        // What the file is comes from the file, not from what was claimed.
        ->assertJsonPath('data.items.0.media_type', 'video');

    Queue::assertPushed(PublishTikTokPost::class);

    $drafter = TikTokFixtures::user(['tiktok-posts.view', 'tiktok-posts.create']);
    $theirs = TikTokFixtures::connection($drafter);

    $this->actingAs($drafter)->postJson("/api/tiktok/accounts/{$theirs->id}/posts", [
        'media_type' => 'video',
        'items' => [['path' => tiktokFile($drafter), 'media_type' => 'video']],
        'publish_now' => true,
    ])->assertForbidden();
});

test('a schedule waits for the scheduler, which fires it when due', function () {
    Queue::fake();
    $user = TikTokFixtures::user(['tiktok-posts.view', 'tiktok-posts.create']);
    $connection = TikTokFixtures::connection($user);

    $this->actingAs($user)->postJson("/api/tiktok/accounts/{$connection->id}/posts", [
        'media_type' => 'video',
        'items' => [['path' => tiktokFile($user), 'media_type' => 'video']],
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.status', 'scheduled');

    Queue::assertNothingPushed();

    TikTokPost::query()->update(['scheduled_at' => now()->subMinute()]);
    $this->artisan('tiktok:publish-scheduled')->assertSuccessful();

    Queue::assertPushed(PublishTikTokPost::class);
    expect(TikTokPost::sole()->status)->toBe(PostStatus::Queued);
});

test('another workspace\'s file, or a video post with two files, is refused', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);
    $stranger = TikTokFixtures::user();

    $this->actingAs($user)->postJson("/api/tiktok/accounts/{$connection->id}/posts", [
        'media_type' => 'video',
        'items' => [['path' => tiktokFile($stranger), 'media_type' => 'video']],
        'publish_now' => true,
    ])->assertJsonValidationErrors('items');

    $this->actingAs($user)->postJson("/api/tiktok/accounts/{$connection->id}/posts", [
        'media_type' => 'video',
        'items' => [
            ['path' => tiktokFile($user, 'a.mp4'), 'media_type' => 'video'],
            ['path' => tiktokFile($user, 'b.mp4'), 'media_type' => 'video'],
        ],
        'publish_now' => true,
    ])->assertJsonValidationErrors('items');
});

test('a video is handed to TikTok, then polled until it is live', function () {
    TikTokFixtures::verifyPrefix();
    Queue::fake();
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);
    $post = tiktokDraft($user, $connection, ['disable_duet' => true, 'is_brand_organic' => true]);

    $polls = 0;
    TikTokFixtures::fake([
        '/business/video/publish/' => TikTokFixtures::ok(['share_id' => 'v_pub_url~v1.123']),
        '/business/publish/status/' => function () use (&$polls) {
            $polls++;

            return $polls === 1
                ? TikTokFixtures::ok(['status' => 'PROCESSING_DOWNLOAD'])
                : TikTokFixtures::ok(['status' => 'PUBLISH_COMPLETE', 'post_ids' => ['7300000000000000099']]);
        },
    ]);

    tiktokPass($post);
    expect($post->fresh()->share_id)->toBe('v_pub_url~v1.123');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/business/video/publish/')
        && $request['business_id'] === 'open-id-123'
        && str_starts_with($request['video_url'], 'http://localhost/storage/tiktok/')
        && $request['post_info']['caption'] === 'Novidade #loja'
        && $request['post_info']['disable_duet'] === true
        && $request['post_info']['is_brand_organic'] === true);

    tiktokPass($post);
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);

    tiktokPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->tiktok_item_id)->toBe('7300000000000000099');

    // Polled, never published a second time.
    Http::assertSentCount(3);
});

test('a photo post goes to the photo endpoint with its title and privacy', function () {
    TikTokFixtures::verifyPrefix();
    Queue::fake();
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);
    $post = tiktokDraft($user, $connection, [
        'media_type' => 'photo',
        'title' => 'Coleção',
        'privacy_level' => 'SELF_ONLY',
    ], tiktokFile($user, 'foto.jpg'));

    TikTokFixtures::fake(['/business/photo/publish/' => TikTokFixtures::ok(['share_id' => 'p_pub_1'])]);

    tiktokPass($post);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/business/photo/publish/')
        && count($request['photo_images']) === 1
        && $request['post_info']['title'] === 'Coleção'
        && $request['post_info']['privacy_level'] === 'SELF_ONLY');
});

test('an address TikTok has not verified fails before TikTok is called, as our problem', function () {
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);
    $post = tiktokDraft($user, $connection);
    Http::fake();

    tiktokPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Failed)
        ->and($post->error)->toContain('Fale com o suporte');
    Http::assertNothingSent();
});

test('TikTok\'s failure reason becomes a sentence the customer can act on', function () {
    TikTokFixtures::verifyPrefix();
    Queue::fake();
    $user = TikTokFixtures::user();
    $connection = TikTokFixtures::connection($user);
    $post = tiktokDraft($user, $connection, ['status' => PostStatus::Publishing, 'share_id' => 'v_pub_1', 'attempts' => 1]);

    TikTokFixtures::fake([
        '/business/publish/status/' => TikTokFixtures::ok(['status' => 'FAILED', 'reason' => 'spam_risk_too_many_posts']),
    ]);

    tiktokPass($post);
    $post->refresh();

    expect($post->status)->toBe(PostStatus::Failed)
        ->and($post->error)->toContain('15 vídeos por dia')
        ->and($post->error)->not->toContain('spam_risk');
});

test('connect asks for the post permissions only once the Back Office turns them on', function () {
    Setting::set(TikTokConfig::KEY_APP_ID, 'app-1');
    Setting::set(TikTokConfig::KEY_REDIRECT_URI, 'https://chat.example/oauth/tiktok/callback');

    expect(TikTokAuthClient::authorizeUrl('state'))->not->toContain('video.publish');

    Setting::set(TikTokConfig::KEY_POSTS_ENABLED, '1');

    expect(urldecode(TikTokAuthClient::authorizeUrl('state')))
        ->toContain('video.publish')
        ->toContain('comment.list.manage')
        ->toContain('message.list.send');
});
