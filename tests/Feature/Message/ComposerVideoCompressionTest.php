<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Exceptions\VideoCompressionException;
use App\Jobs\SendCompressedVideo;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Media\MediaStorage;
use App\Services\Media\VideoCompressor;
use App\Services\Message\StagedVideoUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\GalleryFixtures;

/**
 * Videos from the composer: up to 200 MB in, compressed to fit the channel
 * on the way out.
 *
 * The ffmpeg run itself is covered by the last test, which only runs where
 * the binary exists (production has it; most laptops do not). Everything
 * else here is the routing around it: what is sent in the request, what goes
 * to the queue, and what the thread says when it cannot be sent.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake('outbound-staging');
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-1']])]);
});

function composerVideoConversation(Channel $channel = Channel::WhatsappApiway): Conversation
{
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $user = $tenant->user;
    $user->assignRole(Role::findOrCreate('owner', 'web'));

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id, 'channel' => $channel, 'external_id' => '5511999999999',
        'name' => 'Ana', 'username' => '5511999999999',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id, 'connection_id' => $connection->id, 'user_id' => $user->id,
        'external_id' => '5511999999999', 'status' => ConversationStatus::Active,
    ])->load('connection');
}

/** Enough of an MP4 for the content sniffer to call it video/mp4. */
function tinyMp4(): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512);
}

function compressorAvailable(bool $available = true): void
{
    test()->mock(VideoCompressor::class, fn ($mock) => $mock->shouldReceive('available')->andReturn($available));
}

it('gives each channel one video limit, which its handler and the compressor both read', function () {
    $mb = 1024 * 1024;

    expect(Channel::WhatsappOfficial->videoMaxBytes())->toBe(16 * $mb)
        ->and(Channel::WhatsappApiway->videoMaxBytes())->toBe(50 * $mb)
        ->and(Channel::Telegram->videoMaxBytes())->toBe(50 * $mb)
        ->and(Channel::Instagram->videoMaxBytes())->toBe(25 * $mb)
        ->and(Channel::Messenger->videoMaxBytes())->toBe(25 * $mb)
        ->and(Channel::Discord->videoMaxBytes())->toBe(10 * $mb)
        ->and(Channel::TikTok->videoMaxBytes())->toBeNull();
});

it('aims the bitrate at the size limit and refuses a video too long to watch', function () {
    $sixteen = 16 * 1024 * 1024;

    // A minute fits comfortably: 720p, full audio.
    $minute = VideoCompressor::plan(60, true, $sixteen);
    expect($minute['short_side'])->toBe(720)
        ->and($minute['audio_kbps'])->toBe(96)
        ->and(($minute['video_kbps'] + $minute['audio_kbps']) * 60 * 1000 / 8)->toBeLessThan($sixteen);

    // Five minutes: a smaller picture, leaner sound.
    $five = VideoCompressor::plan(300, true, $sixteen);
    expect($five['short_side'])->toBe(360)
        ->and($five['audio_kbps'])->toBe(48);

    // A short clip under a generous limit does not spend more than it needs.
    expect(VideoCompressor::plan(10, true, 50 * 1024 * 1024)['video_kbps'])->toBe(2500);

    // Half an hour cannot fit in 16 MB at any watchable rate — refused with
    // the duration that would.
    try {
        VideoCompressor::plan(1800, true, $sixteen);
        $this->fail('Expected the video to be refused as too long.');
    } catch (VideoCompressionException $e) {
        expect($e->reason)->toBe(VideoCompressionException::TOO_LONG)
            ->and($e->maxSeconds)->toBeGreaterThan(300)->toBeLessThan(900);
    }
});

it('queues a video over its channel limit instead of sending it in the request', function () {
    Queue::fake();
    compressorAvailable();
    $conversation = composerVideoConversation();
    Sanctum::actingAs($conversation->agent);

    // 60 MB against API Way's 50.
    $response = $this->post("/api/conversations/{$conversation->id}/send-video", [
        'video' => UploadedFile::fake()->create('ferias.mp4', 60 * 1024, 'video/mp4'),
        'message' => 'Olha o vídeo',
        'client_ref' => 'ref-abcdef123',
    ], ['Accept' => 'application/json']);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.client_ref', 'ref-abcdef123')
        ->assertJsonPath('data.limit_bytes', 50 * 1024 * 1024);

    Queue::assertPushed(SendCompressedVideo::class, function (SendCompressedVideo $job) use ($conversation) {
        // PHP deletes its upload temp file when the request ends, so the job
        // must be pointed at a copy of its own.
        return $job->conversationId === $conversation->id
            && $job->source['kind'] === 'staged'
            && MediaStorage::staging()->exists($job->source['path'])
            && $job->payload['message'] === 'Olha o vídeo'
            && $job->clientRef === 'ref-abcdef123';
    });

    Http::assertNothingSent();
});

it('queues a library video too big for the channel, reading it from the gallery', function () {
    Queue::fake();
    compressorAvailable();
    $conversation = composerVideoConversation();
    Sanctum::actingAs($conversation->agent);

    $asset = GalleryFixtures::asset($conversation->connection->tenant, 120 * 1024 * 1024, type: 'video');

    $this->postJson("/api/conversations/{$conversation->id}/send-video", [
        'gallery_asset_id' => $asset->id,
    ])->assertStatus(202);

    Queue::assertPushed(SendCompressedVideo::class, fn (SendCompressedVideo $job) => $job->source['kind'] === 'gallery'
        && $job->source['asset_id'] === $asset->id);

    expect($asset->fresh()->last_used_at)->not->toBeNull();
});

it('sends a video that fits in the request, exactly as before', function () {
    Queue::fake();
    $conversation = composerVideoConversation();
    Sanctum::actingAs($conversation->agent);

    $this->post("/api/conversations/{$conversation->id}/send-video", [
        'video' => UploadedFile::fake()->createWithContent('clip.mp4', tinyMp4()),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.message_type', 'video');

    Queue::assertNotPushed(SendCompressedVideo::class);
});

it('takes a large video in pieces and sends it by the token the last piece returns', function () {
    Queue::fake();
    $conversation = composerVideoConversation();
    Sanctum::actingAs($conversation->agent);
    $bytes = tinyMp4();

    $piece = $this->post("/api/conversations/{$conversation->id}/video-upload", [
        'upload_id' => 'upload-0123456789abcdef',
        'index' => 0,
        'total' => 1,
        'size' => strlen($bytes),
        'name' => 'clip.mp4',
        'chunk' => UploadedFile::fake()->createWithContent('clip.mp4', $bytes),
    ], ['Accept' => 'application/json']);

    $piece->assertCreated()->assertJsonPath('data.done', true);
    $token = $piece->json('data.upload_token');
    expect($token)->toBeString();

    $this->post("/api/conversations/{$conversation->id}/send-video", [
        'upload_token' => $token,
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.message_type', 'video');

    // Sent and gone: nothing staged is left behind, and the token is spent.
    expect(MediaStorage::staging()->allFiles())->toBe([]);

    $this->post("/api/conversations/{$conversation->id}/send-video", ['upload_token' => $token], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('upload_token');
});

it('finds nothing behind a token uploaded for another conversation', function () {
    $mine = composerVideoConversation();
    $other = Conversation::create([
        'contact_id' => $mine->contact_id, 'connection_id' => $mine->connection_id, 'user_id' => $mine->user_id,
        'external_id' => '5511888888888', 'status' => ConversationStatus::Active,
    ]);
    Sanctum::actingAs($mine->agent);

    MediaStorage::staging()->put('chat-video/1/x/clip.mp4', tinyMp4());
    $token = app(StagedVideoUploads::class)->remember($mine->agent, $other, ['path' => 'chat-video/1/x/clip.mp4', 'name' => 'clip.mp4', 'size' => 10]);

    $this->post("/api/conversations/{$mine->id}/send-video", ['upload_token' => $token], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('upload_token');
});

it('refuses a video over the upload ceiling before any byte is kept', function () {
    $conversation = composerVideoConversation();
    Sanctum::actingAs($conversation->agent);

    $this->post("/api/conversations/{$conversation->id}/video-upload", [
        'upload_id' => 'upload-0123456789abcdef',
        'index' => 0,
        'total' => 26,
        'size' => 201 * 1024 * 1024,
        'name' => 'longo.mp4',
        'chunk' => UploadedFile::fake()->createWithContent('longo.mp4', tinyMp4()),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('size');
});

it('says so plainly when a video is too big and nothing here can compress it', function () {
    compressorAvailable(false);
    $conversation = composerVideoConversation();
    Sanctum::actingAs($conversation->agent);

    $this->post("/api/conversations/{$conversation->id}/send-video", [
        'video' => UploadedFile::fake()->create('ferias.mp4', 60 * 1024, 'video/mp4'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', VideoCompressionException::UNAVAILABLE);
});

it('sends a staged video and tags it with the composer reference', function () {
    $conversation = composerVideoConversation();
    MediaStorage::staging()->put('chat-video/1/abc/ferias.mp4', tinyMp4());

    (new SendCompressedVideo(
        $conversation->id,
        $conversation->user_id,
        ['kind' => 'staged', 'path' => 'chat-video/1/abc/ferias.mp4', 'name' => 'ferias.mp4', 'size' => strlen(tinyMp4())],
        ['message' => 'Olha o vídeo'],
        'ref-abcdef123',
    ))->handle(app(VideoCompressor::class));

    $message = Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::Video)->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('Olha o vídeo')
        ->and($message->meta['client_ref'])->toBe('ref-abcdef123')
        ->and($message->sent_by_user_id)->toBe($conversation->user_id)
        // The staged copy is the job's to remove once the channel has its own.
        ->and(MediaStorage::staging()->allFiles())->toBe([]);
});

it('hands a video over the limit to the compressor and sends what comes back', function () {
    $conversation = composerVideoConversation(Channel::Discord);
    $conversation->connection->update(['credentials' => ['token' => 'bot-token']]);

    // 11 MB of staged bytes against Discord's 10.
    MediaStorage::staging()->put('chat-video/1/abc/ferias.mov', str_repeat('x', 11 * 1024 * 1024));

    $compressed = sys_get_temp_dir().'/'.uniqid('fit_', true).'.mp4';
    file_put_contents($compressed, tinyMp4());

    $compressor = Mockery::mock(VideoCompressor::class);
    $compressor->shouldReceive('fit')
        ->once()
        ->withArgs(fn ($path, $limit, $name) => $limit === 10 * 1024 * 1024 && $name === 'ferias.mov')
        ->andReturn(new UploadedFile($compressed, 'ferias.mp4', 'video/mp4', null, true));

    Http::fake([
        '*/users/@me/channels' => Http::response(['id' => 'DM-1']),
        '*' => Http::response(['id' => 'DISCORD-MSG-1', 'timestamp' => now()->toIso8601String(), 'attachments' => []]),
    ]);

    (new SendCompressedVideo(
        $conversation->id,
        $conversation->user_id,
        ['kind' => 'staged', 'path' => 'chat-video/1/abc/ferias.mov', 'name' => 'ferias.mov', 'size' => 11 * 1024 * 1024],
        [],
        'ref-discord-1',
    ))->handle($compressor);

    $message = Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::Video)->first();

    expect($message)->not->toBeNull()
        ->and($message->meta['video_compressed']['original_bytes'])->toBe(11 * 1024 * 1024)
        // The compressed copy is a temp file the job made; it does not outlive the send.
        ->and(is_file($compressed))->toBeFalse();
});

it('writes a note in the thread when a video is too long to fit', function () {
    $conversation = composerVideoConversation(Channel::Discord);
    MediaStorage::staging()->put('chat-video/1/abc/longo.mp4', str_repeat('x', 11 * 1024 * 1024));

    $compressor = Mockery::mock(VideoCompressor::class);
    $compressor->shouldReceive('fit')->once()->andThrow(VideoCompressionException::tooLong(360));

    (new SendCompressedVideo(
        $conversation->id,
        $conversation->user_id,
        ['kind' => 'staged', 'path' => 'chat-video/1/abc/longo.mp4', 'name' => 'longo.mp4', 'size' => 11 * 1024 * 1024],
        [],
        'ref-too-long',
    ))->handle($compressor);

    $note = Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::Info)->sole();

    expect($note->meta['info']['code'])->toBe('video_send_failed')
        ->and($note->meta['info']['params']['reason_code'])->toBe(VideoCompressionException::TOO_LONG)
        ->and($note->meta['info']['params']['max_minutes'])->toBe(6)
        ->and($note->meta['info']['params']['client_ref'])->toBe('ref-too-long')
        ->and(Message::where('message_type', MessageType::Video)->exists())->toBeFalse()
        ->and(MediaStorage::staging()->allFiles())->toBe([]);
});

it('sends nothing to a thread that was resolved while the video waited', function () {
    $conversation = composerVideoConversation();
    $conversation->update(['status' => ConversationStatus::Resolved]);
    MediaStorage::staging()->put('chat-video/1/abc/ferias.mp4', tinyMp4());

    $compressor = Mockery::mock(VideoCompressor::class);
    $compressor->shouldNotReceive('fit');

    (new SendCompressedVideo(
        $conversation->id,
        $conversation->user_id,
        ['kind' => 'staged', 'path' => 'chat-video/1/abc/ferias.mp4', 'name' => 'ferias.mp4', 'size' => 10],
        [],
        'ref-closed-1',
    ))->handle($compressor);

    // Resolving wrote its own status note; this is the other one.
    $note = Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->get()
        ->sole(fn (Message $message) => ($message->meta['info']['code'] ?? null) === 'video_send_failed');

    expect($note->meta['info']['params']['reason_code'])->toBe('conversation_closed');
    Http::assertNothingSent();
});

it('runs a job delivered twice only once', function () {
    $conversation = composerVideoConversation();
    MediaStorage::staging()->put('chat-video/1/abc/ferias.mp4', tinyMp4());

    $job = fn () => new SendCompressedVideo(
        $conversation->id,
        $conversation->user_id,
        ['kind' => 'staged', 'path' => 'chat-video/1/abc/ferias.mp4', 'name' => 'ferias.mp4', 'size' => 10],
        [],
        'ref-twice-01',
    );

    $job()->handle(app(VideoCompressor::class));
    MediaStorage::staging()->put('chat-video/1/abc/ferias.mp4', tinyMp4());
    $job()->handle(app(VideoCompressor::class));

    expect(Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::Video)->count())->toBe(1);
});

it('really fits a video under the limit with ffmpeg', function () {
    $compressor = app(VideoCompressor::class);

    if (! $compressor->available()) {
        $this->markTestSkipped('ffmpeg and ffprobe are not installed here.');
    }

    $source = sys_get_temp_dir().'/'.uniqid('src_', true).'.mp4';
    exec(sprintf(
        'ffmpeg -hide_banner -loglevel error -y -f lavfi -i testsrc2=size=1920x1080:rate=60 -f lavfi -i sine=frequency=440 -t 8 -c:v libx264 -preset ultrafast -crf 12 -c:a aac %s 2>&1',
        escapeshellarg($source),
    ));

    $limit = 1024 * 1024;
    expect(filesize($source))->toBeGreaterThan($limit);

    $fitted = $compressor->fit($source, $limit, 'teste.mov');

    expect($fitted->getSize())->toBeLessThanOrEqual($limit)
        ->and($fitted->getClientOriginalName())->toBe('teste.mp4')
        ->and($compressor->probe($fitted->getRealPath())['duration'])->toBeGreaterThan(7);

    @unlink($source);
    @unlink($fitted->getRealPath());
});
