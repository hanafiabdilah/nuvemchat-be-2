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
function waAudioFile(string $name, string $mime, ?string $bytes = null): UploadedFile
{
    $file = UploadedFile::fake()->create($name, 8, $mime);

    file_put_contents($file->getRealPath(), $bytes ?? random_bytes(512));

    return $file;
}

/**
 * One Ogg page: the 27-byte header plus a token payload.
 *
 * Only the first six bytes decide anything here — "OggS", the zero version
 * byte, and bit 0x02 of the header type, which marks the page that begins a
 * logical stream. The rest is written out so the fixture is a page and not a
 * shape that happens to pass.
 */
function waOggPage(int $serial, bool $beginsStream, int $sequence = 0): string
{
    return 'OggS'
        . "\0"
        . chr($beginsStream ? 0x02 : 0x00)
        . str_repeat("\0", 8)   // granule position
        . pack('V', $serial)
        . pack('V', $sequence)
        . pack('V', 0)          // CRC, unchecked by the detector
        . chr(1) . chr(8)       // one segment, eight bytes
        . ($beginsStream ? 'OpusHead' : 'audiodat');
}

/** What every working voice note looks like: one stream, however many pages. */
function waSingleStreamOgg(): string
{
    return waOggPage(11, true) . waOggPage(11, false, 1) . waOggPage(11, false, 2);
}

/**
 * What the hub hands back for a long spoken reply: two synthesised parts
 * concatenated, so the second one opens a stream of its own. Legal Ogg, and
 * unplayable on WhatsApp — the shape was read off eight production files.
 */
function waChainedOgg(): string
{
    return waSingleStreamOgg() . waOggPage(22, true) . waOggPage(22, false, 1);
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
        'audio' => waAudioFile('resposta.ogg', 'audio/ogg', waSingleStreamOgg()),
    ]);

    expect(waAudioDeclaredType())->toBe('audio/ogg')
        ->and(waAudioMessagePayload()['voice'] ?? null)->toBeTrue();
});

test('a chained ogg is re-encoded instead of being passed through', function () {
    waAudioFakeCloudApi();

    $converted = false;

    app()->instance(AudioNormalizer::class, new class($converted) extends AudioNormalizer
    {
        public function __construct(private bool &$converted) {}

        public function available(): bool
        {
            return true;
        }

        public function toOggOpus(UploadedFile $file): UploadedFile
        {
            $this->converted = true;

            $path = tempnam(sys_get_temp_dir(), 'fake_opus_');
            file_put_contents($path, waSingleStreamOgg());

            return new UploadedFile($path, 'resposta.ogg', 'audio/ogg', null, true);
        }
    });

    (new MessageService)->sendAudio(waAudioConversation(), [
        'audio' => waAudioFile('resposta.ogg', 'audio/ogg', waChainedOgg()),
    ]);

    // Without this the send succeeds at every layer we can see — Meta accepts
    // the upload, the message is delivered and read — and the recipient is
    // told the audio is no longer available and to ask the number to re-send.
    expect($converted)->toBeTrue()
        // And the repair must not cost the voice-note bubble it was sent for.
        ->and(waAudioDeclaredType())->toBe('audio/ogg')
        ->and(waAudioMessagePayload()['voice'] ?? null)->toBeTrue();
});

/** Every page granule, in order, read straight off the Ogg headers. */
function waOggGranules(string $path): array
{
    $data = file_get_contents($path);
    $granules = [];
    $offset = 0;

    while ($offset + 27 <= strlen($data) && substr($data, $offset, 4) === 'OggS') {
        $segments = ord($data[$offset + 26]);
        $table = substr($data, $offset + 27, $segments);
        $body = 0;

        for ($i = 0; $i < $segments; $i++) {
            $body += ord($table[$i]);
        }

        $granules[] = unpack('P', substr($data, $offset + 6, 8))[1];
        $offset += 27 + $segments + $body;
    }

    return $granules;
}

test('a converted recording starts its own timeline', function () {
    $ffmpeg = (new AudioNormalizer)->available() ? config('media.ffmpeg_path') ?: 'ffmpeg' : null;

    if ($ffmpeg === null) {
        $this->markTestSkipped('ffmpeg is not installed here; this asserts what it produces.');
    }

    // What a browser hands over: Opus in WebM, whose codec delay and capture
    // clock put the first sample somewhere other than zero.
    $source = tempnam(sys_get_temp_dir(), 'rec_') . '.webm';
    exec(sprintf(
        '%s -hide_banner -loglevel error -y -f lavfi -i sine=frequency=440:duration=3 -ac 1 -c:a libopus -b:a 32k -f webm %s 2>&1',
        escapeshellarg($ffmpeg),
        escapeshellarg($source),
    ), $out, $status);

    if ($status !== 0 || ! is_file($source)) {
        $this->markTestSkipped('this ffmpeg cannot synthesise the WebM the test needs.');
    }

    $converted = (new AudioNormalizer)->toOggOpus(
        new UploadedFile($source, 'nota.webm', 'video/webm', null, true)
    );

    $granules = waOggGranules($converted->getRealPath());

    // The two header pages carry granule 0, and the last page may be trimmed
    // to the real end of the audio. Everything between must land on a whole
    // Opus frame — 120 samples at 48 kHz. Carrying the source's offset here is
    // what WhatsApp accepts, renders, and then refuses to play.
    $audioPages = array_slice($granules, 2, -1);

    expect($audioPages)->not->toBeEmpty();

    foreach ($audioPages as $index => $granule) {
        expect($granule % 120)->toBe(0, "page {$index} granule {$granule}");
    }

    @unlink($source);
    @unlink($converted->getRealPath());
});

test('the chained-stream check reads pages, not markers', function () {
    $path = tempnam(sys_get_temp_dir(), 'ogg_check_');

    $cases = [
        // One stream over several pages is the normal case and must not be
        // sent through ffmpeg: it would make a working send depend on a binary.
        [waSingleStreamOgg(), false],
        [waChainedOgg(), true],
        // A second stream anywhere in the file, not just appended at the end.
        [waOggPage(1, true) . waOggPage(2, true) . waOggPage(1, false, 1), true],
        // "OggS" and "OpusHead" inside audio data decide nothing on their own:
        // a page needs the zero version byte and the beginning-of-stream bit.
        [waSingleStreamOgg() . 'OggS' . "\x02\x02" . 'OpusHead' . 'OggS', false],
        [random_bytes(512), false],
        ['', false],
    ];

    foreach ($cases as $index => [$bytes, $expected]) {
        file_put_contents($path, $bytes);

        expect(AudioNormalizer::isChainedOgg($path))->toBe($expected, "case {$index}");
    }

    @unlink($path);

    // A file that is not there is not a chained stream; it is a different
    // failure, and one the send path already reports in its own words.
    expect(AudioNormalizer::isChainedOgg($path))->toBeFalse();
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
