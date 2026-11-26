<?php

use App\Enums\Tutorial\TutorialTopic;
use App\Enums\Tutorial\TutorialVideoStatus;
use App\Exceptions\TutorialVideoException;
use App\Jobs\ProcessTutorialVideo;
use App\Models\Admin;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\TutorialVideo;
use App\Models\TutorialVideoTopic;
use App\Models\User;
use App\Services\Media\ChunkedUpload;
use App\Services\Media\MediaStorage;
use App\Services\Tutorials\TutorialVideoProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(MediaStorage::publishedDiskName());
    Storage::fake((string) config('media.video.staging_disk', 'outbound-staging'));
});

/** A processor that pretends ffmpeg ran: what the job does with the result is what is under test. */
class FakeTutorialProcessor extends TutorialVideoProcessor
{
    public ?string $failWith = null;

    public function process(string $sourcePath): array
    {
        if ($this->failWith !== null) {
            throw new TutorialVideoException($this->failWith);
        }

        $directory = 'tutorials/'.bin2hex(random_bytes(6));
        MediaStorage::published()->put($directory.'/video.mp4', 'processed');
        MediaStorage::published()->put($directory.'/poster.jpg', 'poster');

        return [
            'path' => $directory.'/video.mp4',
            'poster_path' => $directory.'/poster.jpg',
            'duration_seconds' => 73,
            'width' => 1080,
            'height' => 1920,
            'size_bytes' => 9,
            'note' => null,
        ];
    }
}

function tutorialAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.tutorials.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

/** A workspace member holding exactly `$permissions`. */
function tutorialUser(array $permissions = [], string $market = 'BR'): User
{
    $user = User::factory()->create(['email' => 'tutorial-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = $market;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

/** A ready video already in the library. */
function tutorialVideo(array $topics, array $attributes = []): TutorialVideo
{
    $video = TutorialVideo::create([
        'title' => 'Video '.uniqid(),
        'locale' => 'pt_BR',
        'is_active' => true,
        'status' => TutorialVideoStatus::Ready,
        'path' => 'tutorials/'.uniqid().'/video.mp4',
        'poster_path' => null,
        'duration_seconds' => 60,
        ...$attributes,
    ]);

    foreach ($topics as $topic) {
        $last = TutorialVideoTopic::query()->where('topic', $topic)->max('position');
        $video->topics()->create(['topic' => $topic, 'position' => $last === null ? 0 : $last + 1]);
    }

    return $video;
}

/** Bytes libmagic reads as an MP4, which is all the chunked upload sniffs. */
function tutorialMp4Bytes(int $size = 512): string
{
    $header = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

    return $header.str_repeat("\x00", max(0, $size - strlen($header)));
}

/** Upload a file through the Back Office's piece endpoint and return its token. */
function tutorialUpload($test, Admin $admin, string $name = 'tour.mp4'): string
{
    $bytes = tutorialMp4Bytes();
    $chunk = UploadedFile::fake()->createWithContent('blob', $bytes);

    $response = $test->actingAs($admin)->post('/api/admin/tutorials/uploads', [
        'upload_id' => 'upload-'.bin2hex(random_bytes(8)),
        'index' => 0,
        'total' => 1,
        'size' => strlen($bytes),
        'name' => $name,
        'chunk' => $chunk,
    ], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.done', true);

    return $response->json('data.upload.token');
}

// --- Back Office -----------------------------------------------------------------

it('keeps the library to admins who may manage it', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin)->getJson('/api/admin/tutorials')->assertForbidden();
    $this->actingAs(tutorialUser())->getJson('/api/admin/tutorials')->assertForbidden();
});

it('lists every topic for the Back Office to pick from', function () {
    $meta = $this->actingAs(tutorialAdmin())->getJson('/api/admin/tutorials/meta')->assertOk();

    expect($meta->json('topics'))->toHaveCount(count(TutorialTopic::cases()))
        ->and(collect($meta->json('topics'))->firstWhere('key', 'connections.telegram'))
        ->toMatchArray(['label' => 'Telegram', 'area' => 'connections', 'parent' => 'connections'])
        ->and($meta->json('chunk_bytes'))->toBe(ChunkedUpload::CHUNK_BYTES);
});

it('uploads a video in pieces, processes it and publishes it', function () {
    $this->instance(TutorialVideoProcessor::class, new FakeTutorialProcessor);
    $admin = tutorialAdmin();
    $token = tutorialUpload($this, $admin);

    $created = $this->actingAs($admin)->postJson('/api/admin/tutorials', [
        'title' => 'Conectando o Telegram',
        'description' => 'Do BotFather até a primeira mensagem.',
        'locale' => 'pt_BR',
        'topics' => ['connections.telegram', 'connections'],
        'auto_open' => true,
        'upload_token' => $token,
    ])->assertCreated();

    $video = TutorialVideo::findOrFail($created->json('data.id'));

    // The queue is synchronous in tests, so the job already ran.
    expect($video->status)->toBe(TutorialVideoStatus::Ready)
        ->and($video->source_path)->toBeNull()
        ->and($video->duration_seconds)->toBe(73)
        ->and($video->auto_open)->toBeTrue()
        ->and($video->topicKeys())->toEqualCanonicalizing(['connections', 'connections.telegram']);

    Storage::disk(MediaStorage::publishedDiskName())->assertExists($video->path);
    // The upload itself is gone from staging once the processed copy exists.
    expect(MediaStorage::staging()->allFiles())->toBe([]);
});

it('refuses an upload token that was already used or is somebody else\'s', function () {
    $this->instance(TutorialVideoProcessor::class, new FakeTutorialProcessor);
    $admin = tutorialAdmin();
    $token = tutorialUpload($this, $admin);

    $payload = ['title' => 'Tour', 'locale' => 'pt_BR', 'topics' => ['dashboard'], 'upload_token' => $token];

    $other = Admin::factory()->create();
    $other->assignRole('super-admin');
    $this->actingAs($other)->postJson('/api/admin/tutorials', $payload)->assertUnprocessable()->assertJsonValidationErrors('upload_token');

    // The failed attempt consumed it too: a token is good for one form.
    $this->actingAs($admin)->postJson('/api/admin/tutorials', $payload)->assertUnprocessable()->assertJsonValidationErrors('upload_token');
});

it('refuses a topic the dashboard does not know', function () {
    $admin = tutorialAdmin();

    $this->actingAs($admin)->postJson('/api/admin/tutorials', [
        'title' => 'Tour', 'locale' => 'pt_BR', 'topics' => ['connections.whatsapp'], 'upload_token' => 'x',
    ])->assertUnprocessable()->assertJsonValidationErrors('topics.0');
});

it('marks a video that cannot be processed as failed, with the reason', function () {
    $processor = new FakeTutorialProcessor;
    $processor->failWith = 'This file could not be read as a video.';
    $this->instance(TutorialVideoProcessor::class, $processor);
    $admin = tutorialAdmin();

    $id = $this->actingAs($admin)->postJson('/api/admin/tutorials', [
        'title' => 'Broken', 'locale' => 'pt_BR', 'topics' => ['dashboard'], 'upload_token' => tutorialUpload($this, $admin),
    ])->assertCreated()->json('data.id');

    $video = TutorialVideo::findOrFail($id);
    expect($video->status)->toBe(TutorialVideoStatus::Failed)
        ->and($video->error)->toBe('This file could not be read as a video.')
        ->and(MediaStorage::staging()->allFiles())->toBe([]);
});

it('keeps the old file playing when its replacement fails', function () {
    $processor = new FakeTutorialProcessor;
    $this->instance(TutorialVideoProcessor::class, $processor);
    $admin = tutorialAdmin();

    $id = $this->actingAs($admin)->postJson('/api/admin/tutorials', [
        'title' => 'Tour', 'locale' => 'pt_BR', 'topics' => ['dashboard'], 'upload_token' => tutorialUpload($this, $admin),
    ])->json('data.id');
    $first = TutorialVideo::findOrFail($id)->path;

    $processor->failWith = 'Bad file.';
    $this->actingAs($admin)->putJson("/api/admin/tutorials/{$id}", ['upload_token' => tutorialUpload($this, $admin)])->assertOk();

    $video = TutorialVideo::findOrFail($id);
    expect($video->status)->toBe(TutorialVideoStatus::Ready)
        ->and($video->path)->toBe($first)
        ->and($video->error)->toContain('previous one is still shown');
    Storage::disk(MediaStorage::publishedDiskName())->assertExists($first);
});

it('replaces the file and deletes the old one once the new one is ready', function () {
    $this->instance(TutorialVideoProcessor::class, new FakeTutorialProcessor);
    $admin = tutorialAdmin();

    $id = $this->actingAs($admin)->postJson('/api/admin/tutorials', [
        'title' => 'Tour', 'locale' => 'pt_BR', 'topics' => ['dashboard'], 'upload_token' => tutorialUpload($this, $admin),
    ])->json('data.id');
    $first = TutorialVideo::findOrFail($id)->path;

    $this->actingAs($admin)->putJson("/api/admin/tutorials/{$id}", ['upload_token' => tutorialUpload($this, $admin)])->assertOk();

    $video = TutorialVideo::findOrFail($id);
    expect($video->path)->not->toBe($first);
    Storage::disk(MediaStorage::publishedDiskName())->assertMissing($first);
    Storage::disk(MediaStorage::publishedDiskName())->assertExists($video->path);
});

it('publishes the upload as it came when ffmpeg is missing', function () {
    config(['media.ffmpeg_path' => '/nonexistent/ffmpeg']);
    $admin = tutorialAdmin();

    $id = $this->actingAs($admin)->postJson('/api/admin/tutorials', [
        'title' => 'Tour', 'locale' => 'pt_BR', 'topics' => ['dashboard'], 'upload_token' => tutorialUpload($this, $admin),
    ])->json('data.id');

    $video = TutorialVideo::findOrFail($id);
    expect($video->status)->toBe(TutorialVideoStatus::Ready)
        ->and($video->path)->toEndWith('/video.mp4')
        ->and($video->poster_path)->toBeNull()
        ->and($video->error)->toContain('ffmpeg is not installed');
});

it('orders a topic\'s videos as dragged, and appends new ones at the end', function () {
    $admin = tutorialAdmin();
    $a = tutorialVideo(['flows']);
    $b = tutorialVideo(['flows']);
    $c = tutorialVideo(['flows', 'dashboard']);

    $this->actingAs($admin)->putJson('/api/admin/tutorials/order', ['topic' => 'flows', 'ids' => [$c->id, $a->id, $b->id]])->assertOk();

    $listed = $this->actingAs($admin)->getJson('/api/admin/tutorials?topic=flows')->assertOk()->json('data.*.id');
    expect($listed)->toBe([$c->id, $a->id, $b->id]);

    // The other topic's order is its own.
    expect(TutorialVideoTopic::where('topic', 'dashboard')->where('tutorial_video_id', $c->id)->value('position'))->toBe(0);

    $this->actingAs($admin)->putJson('/api/admin/tutorials/order', ['topic' => 'flows', 'ids' => [999999]])
        ->assertUnprocessable();
});

it('deletes a video with its files', function () {
    $admin = tutorialAdmin();
    $video = tutorialVideo(['dashboard'], ['poster_path' => 'tutorials/x/poster.jpg', 'path' => 'tutorials/x/video.mp4']);
    MediaStorage::published()->put('tutorials/x/video.mp4', 'v');
    MediaStorage::published()->put('tutorials/x/poster.jpg', 'p');

    $this->actingAs($admin)->deleteJson("/api/admin/tutorials/{$video->id}")->assertOk();

    expect(TutorialVideo::find($video->id))->toBeNull();
    Storage::disk(MediaStorage::publishedDiskName())->assertMissing('tutorials/x/video.mp4');
});

it('does not encode twice, nor fail an encode still running, when the job is delivered again', function () {
    $processor = new FakeTutorialProcessor;
    $processor->failWith = 'should not run';
    $this->instance(TutorialVideoProcessor::class, $processor);

    $video = TutorialVideo::create([
        'title' => 'Long one', 'locale' => 'pt_BR', 'status' => TutorialVideoStatus::Processing,
        'source_path' => 'tutorial-uploads/abc/long.mp4',
    ]);
    $job = new ProcessTutorialVideo($video->id, 'tutorial-uploads/abc/long.mp4');

    // The first delivery is mid-encode.
    Cache::put('tutorial-video:processing:'.$video->id.':'.sha1('tutorial-uploads/abc/long.mp4'), true, 600);

    $job->handle($processor);
    $job->failed(new MaxAttemptsExceededException('again'));

    expect($video->fresh()->status)->toBe(TutorialVideoStatus::Processing)
        ->and($video->fresh()->source_path)->toBe('tutorial-uploads/abc/long.mp4');
});

it('uses a chosen thumbnail over the drawn frame, shrunk, and falls back when it is removed', function () {
    $admin = tutorialAdmin();
    MediaStorage::published()->put('tutorials/v/poster.jpg', 'frame');
    $video = tutorialVideo(['dashboard'], ['poster_path' => 'tutorials/v/poster.jpg', 'path' => 'tutorials/v/video.mp4']);

    $set = $this->actingAs($admin)->post("/api/admin/tutorials/{$video->id}/thumbnail", [
        'thumbnail' => UploadedFile::fake()->image('capa.png', 2400, 1350),
    ], ['Accept' => 'application/json'])->assertOk();

    $thumbnail = $video->fresh()->thumbnail_path;
    expect($set->json('data.has_thumbnail'))->toBeTrue()
        ->and($set->json('data.poster_url'))->toContain($thumbnail)
        ->and($set->json('data.auto_poster_url'))->toContain('tutorials/v/poster.jpg');

    // Shrunk to the longest edge the player needs.
    [$width] = getimagesizefromstring(MediaStorage::published()->get($thumbnail));
    expect($width)->toBe(1280);

    // Viewers see it too.
    $entry = collect($this->actingAs(tutorialUser())->getJson('/api/tutorials')->json('videos'))->firstWhere('id', $video->id);
    expect($entry['poster_url'])->toContain($thumbnail);

    $cleared = $this->actingAs($admin)->deleteJson("/api/admin/tutorials/{$video->id}/thumbnail")->assertOk();
    expect($cleared->json('data.has_thumbnail'))->toBeFalse()
        ->and($cleared->json('data.poster_url'))->toContain('tutorials/v/poster.jpg');
    Storage::disk(MediaStorage::publishedDiskName())->assertMissing($thumbnail);
});

it('refuses a thumbnail that is not a picture it can serve', function () {
    $admin = tutorialAdmin();
    $video = tutorialVideo(['dashboard']);

    $this->actingAs($admin)->post("/api/admin/tutorials/{$video->id}/thumbnail", [
        'thumbnail' => UploadedFile::fake()->createWithContent('capa.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('thumbnail');

    expect($video->fresh()->thumbnail_path)->toBeNull();
});

it('keeps the chosen thumbnail when the video file is replaced, and deletes it with the video', function () {
    $this->instance(TutorialVideoProcessor::class, new FakeTutorialProcessor);
    $admin = tutorialAdmin();

    $id = $this->actingAs($admin)->postJson('/api/admin/tutorials', [
        'title' => 'Tour', 'locale' => 'pt_BR', 'topics' => ['dashboard'], 'upload_token' => tutorialUpload($this, $admin),
    ])->json('data.id');

    $this->actingAs($admin)->post("/api/admin/tutorials/{$id}/thumbnail", [
        'thumbnail' => UploadedFile::fake()->image('capa.jpg', 640, 360),
    ], ['Accept' => 'application/json'])->assertOk();
    $thumbnail = TutorialVideo::findOrFail($id)->thumbnail_path;

    $this->actingAs($admin)->putJson("/api/admin/tutorials/{$id}", ['upload_token' => tutorialUpload($this, $admin)])->assertOk();

    expect(TutorialVideo::findOrFail($id)->thumbnail_path)->toBe($thumbnail);
    Storage::disk(MediaStorage::publishedDiskName())->assertExists($thumbnail);

    $this->actingAs($admin)->deleteJson("/api/admin/tutorials/{$id}")->assertOk();
    Storage::disk(MediaStorage::publishedDiskName())->assertMissing($thumbnail);
});

// --- Dashboard -------------------------------------------------------------------

it('shows a person only playable videos, on topics their role opens', function () {
    tutorialVideo(['dashboard'], ['title' => 'Everyone']);
    tutorialVideo(['flows'], ['title' => 'Flows']);
    tutorialVideo(['dashboard'], ['title' => 'Off', 'is_active' => false]);
    tutorialVideo(['dashboard'], ['title' => 'Still processing', 'status' => TutorialVideoStatus::Processing]);

    $agent = tutorialUser();
    $titles = $this->actingAs($agent)->getJson('/api/tutorials?locale=pt_BR')->assertOk()->json('videos.*.title');
    expect($titles)->toBe(['Everyone']);

    $builder = tutorialUser(['flows.view']);
    $titles = $this->actingAs($builder)->getJson('/api/tutorials?locale=pt_BR')->json('videos.*.title');
    expect($titles)->toEqualCanonicalizing(['Everyone', 'Flows']);
});

it('keeps a topic out of a country that does not sell it', function () {
    Market::create([
        'code' => 'ID', 'name' => 'Indonesia', 'currency' => 'IDR', 'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta', 'phone_country' => '62', 'status' => 'active',
    ]);
    tutorialVideo(['numbers'], ['title' => 'Numbers']);

    $brazil = tutorialUser(['numbers.view'], 'BR');
    $indonesia = tutorialUser(['numbers.view'], 'ID');

    expect($this->actingAs($brazil)->getJson('/api/tutorials')->json('videos.*.title'))->toBe(['Numbers'])
        ->and($this->actingAs($indonesia)->getJson('/api/tutorials')->json('videos'))->toBe([]);
});

it('shows the reader\'s language, and the fallback language only where there is none', function () {
    tutorialVideo(['dashboard'], ['title' => 'Painel', 'locale' => 'pt_BR']);
    tutorialVideo(['dashboard'], ['title' => 'Dasbor', 'locale' => 'id']);
    tutorialVideo(['contacts'], ['title' => 'Contatos', 'locale' => 'pt_BR']);
    tutorialVideo(['tags'], ['title' => 'Tags EN', 'locale' => 'en']);

    $user = tutorialUser();
    $payload = $this->actingAs($user)->getJson('/api/tutorials?locale=id')->assertOk();

    expect($payload->json('videos.*.title'))->toEqualCanonicalizing(['Dasbor', 'Contatos'])
        ->and($payload->json('topics.*.key'))->toBe(['dashboard', 'contacts']);
});

it('records a view and a completion once', function () {
    $video = tutorialVideo(['dashboard']);
    $user = tutorialUser();

    $this->actingAs($user)->postJson("/api/tutorials/{$video->id}/view")->assertOk()->assertJson(['completed' => false]);
    $this->actingAs($user)->postJson("/api/tutorials/{$video->id}/view", ['completed' => true])->assertOk()->assertJson(['completed' => true]);
    $this->actingAs($user)->postJson("/api/tutorials/{$video->id}/view")->assertOk()->assertJson(['completed' => true]);

    $entry = collect($this->actingAs($user)->getJson('/api/tutorials')->json('videos'))->firstWhere('id', $video->id);
    expect($entry)->toMatchArray(['viewed' => true, 'completed' => true])
        ->and($video->views()->count())->toBe(1);

    $hidden = tutorialVideo(['dashboard'], ['is_active' => false]);
    $this->actingAs($user)->postJson("/api/tutorials/{$hidden->id}/view")->assertNotFound();
});

it('opens a topic by itself once per person', function () {
    $user = tutorialUser();

    $this->actingAs($user)->postJson('/api/tutorials/auto-open', ['topic' => 'connections'])->assertOk()->assertJson(['opened' => true]);
    $this->actingAs($user)->postJson('/api/tutorials/auto-open', ['topic' => 'connections'])->assertOk()->assertJson(['opened' => false]);

    expect($this->actingAs($user)->getJson('/api/tutorials')->json('auto_opened'))->toBe(['connections'])
        ->and($this->actingAs(tutorialUser())->postJson('/api/tutorials/auto-open', ['topic' => 'connections'])->json('opened'))->toBeTrue();

    $this->actingAs($user)->postJson('/api/tutorials/auto-open', ['topic' => 'nope'])->assertUnprocessable();
});

// --- Processing --------------------------------------------------------------------

it('re-encodes what browsers may not play and only remuxes what they will', function () {
    $processor = new TutorialVideoProcessor;
    $ready = ['duration' => 60.0, 'width' => 1920, 'height' => 1080, 'has_audio' => true, 'video_codec' => 'h264', 'audio_codec' => 'aac', 'pix_fmt' => 'yuv420p', 'format' => 'mov,mp4,m4a,3gp,3g2,mj2', 'bit_rate' => 3_000_000];

    expect($processor->needsEncode($ready))->toBeFalse()
        ->and($processor->needsEncode([...$ready, 'video_codec' => 'hevc']))->toBeTrue()
        ->and($processor->needsEncode([...$ready, 'format' => 'matroska,webm', 'video_codec' => 'vp9']))->toBeTrue()
        ->and($processor->needsEncode([...$ready, 'width' => 3840, 'height' => 2160]))->toBeTrue()
        ->and($processor->needsEncode([...$ready, 'bit_rate' => 20_000_000]))->toBeTrue()
        ->and($processor->needsEncode([...$ready, 'audio_codec' => null]))->toBeFalse();
});

it('makes a real recording stream-ready, with a poster', function () {
    if (! (new TutorialVideoProcessor)->available()) {
        $this->markTestSkipped('ffmpeg is not installed here.');
    }

    $staging = MediaStorage::staging();
    $source = 'tutorial-uploads/test/clip.mov';
    $staging->makeDirectory(dirname($source));

    Process::run([
        (string) config('media.ffmpeg_path'), '-y', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=25', '-t', '3',
        '-c:v', 'mpeg4', $staging->path($source),
    ])->throw();

    $result = (new TutorialVideoProcessor)->process($source);

    expect($result['poster_path'])->not->toBeNull()
        ->and($result['duration_seconds'])->toBe(3)
        ->and([$result['width'], $result['height']])->toBe([640, 360]);
    Storage::disk(MediaStorage::publishedDiskName())->assertExists($result['path']);
});
