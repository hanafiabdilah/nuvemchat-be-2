<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Exceptions\UserFacingException;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\AudioNormalizer;
use App\Services\Message\Handlers\WhatsappOfficialHandler;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function waAudioConversation(): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'Oficial',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'access_token' => 'TEST_TOKEN',
            'phone_number_id' => 'PN123',
        ],
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'name' => 'Maria',
        'external_id' => '5511888887777',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511888887777',
        'status' => ConversationStatus::Active,
        'user_id' => $user->id,
    ]);
}

function waAudioFakeCloudApi(): void
{
    Storage::fake('local');

    Http::fake([
        '*/PN123/media' => Http::response(['id' => 'MEDIA_ID'], 200),
        '*/PN123/messages' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
    ]);
}

/**
 * A fake upload that has both a declared mime and actual bytes.
 *
 * `UploadedFile::fake()->create()` reports a size but writes a zero-byte file,
 * and `Http::attach()` runs its parts through `array_filter`, which drops an
 * empty `contents` — so a fake alone fails inside Guzzle with "A 'contents'
 * key is required", nothing to do with the code under test.
 */
function waAudioFile(string $name, string $mime): UploadedFile
{
    $file = UploadedFile::fake()->create($name, 8, $mime);

    file_put_contents($file->getRealPath(), random_bytes(512));

    return $file;
}

/** The `type` the upload declared to Meta, read back off the multipart body. */
function waAudioDeclaredType(): ?string
{
    $found = null;

    Http::assertSent(function ($request) use (&$found) {
        if (! str_contains($request->url(), '/PN123/media')) {
            return false;
        }

        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'type') {
                $found = $part['contents'];
            }
        }

        return true;
    });

    return $found;
}

/** The `audio` object of the sent message, which is where `voice` lives. */
function waAudioMessagePayload(): array
{
    $payload = [];

    Http::assertSent(function ($request) use (&$payload) {
        if (! str_contains($request->url(), '/PN123/messages')) {
            return false;
        }

        $payload = $request->data()['audio'] ?? [];

        return true;
    });

    return $payload;
}

function waAudioMime(string $extension): ?string
{
    $ref = new ReflectionMethod(WhatsappOfficialHandler::class, 'audioMime');
    $ref->setAccessible(true);

    return $ref->invoke(null, $extension);
}

test('an mp3 is uploaded untouched and declared as audio/mpeg', function () {
    waAudioFakeCloudApi();

    (new MessageService)->sendAudio(waAudioConversation(), [
        'audio' => waAudioFile('nota.mp3', 'audio/mpeg'),
    ]);

    // Not the sniffer's answer: it is read from the Cloud API table, and for
    // mp3 the two happen to agree. The formats where they do not are below.
    expect(waAudioDeclaredType())->toBe('audio/mpeg')
        // Meta only draws a voice note for Ogg/Opus, so an mp3 must not claim
        // to be one — the flag would be ignored, but so would our intent.
        ->and(waAudioMessagePayload())->not->toHaveKey('voice');
});

test('an ogg is declared as audio/ogg and sent as a voice note', function () {
    waAudioFakeCloudApi();

    // This is the AI voice reply's own path: the hub is asked for Opus and the
    // file is stored as .ogg. It must keep working without ffmpeg present.
    config(['media.ffmpeg_path' => '/nonexistent/ffmpeg']);

    (new MessageService)->sendAudio(waAudioConversation(), [
        'audio' => waAudioFile('resposta.ogg', 'audio/ogg'),
    ]);

    expect(waAudioDeclaredType())->toBe('audio/ogg')
        ->and(waAudioMessagePayload()['voice'] ?? null)->toBeTrue();
});

test('a browser recording is converted to ogg before it reaches Meta', function () {
    waAudioFakeCloudApi();

    app()->instance(AudioNormalizer::class, new class extends AudioNormalizer
    {
        public function available(): bool
        {
            return true;
        }

        public function toOggOpus(UploadedFile $file): UploadedFile
        {
            $path = tempnam(sys_get_temp_dir(), 'fake_opus_');
            file_put_contents($path, 'OggS');

            return new UploadedFile($path, 'nota.ogg', 'audio/ogg', null, true);
        }
    });

    // What Chrome and Edge actually hand back. Note the mime: a WebM container
    // sniffs as *video*/webm even when it holds nothing but a voice note, which
    // is why this used to be uploaded under a type Meta has never accepted.
    (new MessageService)->sendAudio(waAudioConversation(), [
        'audio' => waAudioFile('nota.webm', 'video/webm'),
    ]);

    expect(waAudioDeclaredType())->toBe('audio/ogg')
        // And the conversion is what earns the voice-note bubble, not just the
        // delivery: an accepted format that is not Ogg would arrive as a file.
        ->and(waAudioMessagePayload()['voice'] ?? null)->toBeTrue();
});

test('without a converter the refusal names the formats that work, and Meta is never called', function () {
    waAudioFakeCloudApi();

    config(['media.ffmpeg_path' => '/nonexistent/ffmpeg']);

    $thrown = null;

    try {
        (new MessageService)->sendAudio(waAudioConversation(), [
            'audio' => UploadedFile::fake()->create('nota.webm', 6, 'video/webm'),
        ]);
    } catch (UserFacingException $th) {
        $thrown = $th;
    }

    // The sentence has to survive MessageService::guard(), which translates
    // everything it does not recognise into "não foi possível enviar" — advice
    // to repeat an attempt that fails identically.
    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe(AudioNormalizer::FAILURE_MESSAGE)
        ->and($thrown->getErrorCode())->toBe(AudioNormalizer::FAILURE_CODE)
        ->and($thrown->httpStatus())->toBe(422);

    // Nothing was uploaded: a file we know Meta refuses is not worth the round
    // trip, and a half-finished upload would bill against the media endpoint.
    Http::assertNothingSent();
});

test('audio is capped at the 16 MB Meta itself accepts', function () {
    waAudioFakeCloudApi();

    expect(fn () => (new MessageService)->sendAudio(waAudioConversation(), [
        // Passed the old 25 MB rule, then failed upstream — where the refusal
        // reads as a fault of ours rather than a limit we could have stated.
        'audio' => UploadedFile::fake()->create('longa.mp3', 20000, 'audio/mpeg'),
    ]))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('the Cloud API audio table answers for every accepted spelling and nothing else', function () {
    expect(waAudioMime('mp3'))->toBe('audio/mpeg')
        ->and(waAudioMime('m4a'))->toBe('audio/mp4')
        ->and(waAudioMime('aac'))->toBe('audio/aac')
        ->and(waAudioMime('amr'))->toBe('audio/amr')
        ->and(waAudioMime('ogg'))->toBe('audio/ogg')
        // Same Ogg container, different spelling: answered, never re-encoded.
        ->and(waAudioMime('opus'))->toBe('audio/ogg')
        ->and(waAudioMime('OGG'))->toBe('audio/ogg')
        // Everything here is a format Meta refuses, and each one is a real
        // arrival: webm from Chrome, mp4 from Safari, wav from an upload.
        ->and(waAudioMime('webm'))->toBeNull()
        ->and(waAudioMime('wav'))->toBeNull()
        ->and(waAudioMime('mp4'))->toBeNull()
        ->and(waAudioMime('flac'))->toBeNull();
});
