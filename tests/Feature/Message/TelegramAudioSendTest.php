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
use App\Services\Message\MessageService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use Telegram\Bot\HttpClients\HttpClientInterface;

/*
 * What the Telegram bot is handed when an agent sends a voice note.
 *
 * sendVoice takes OGG/Opus, MP3 or M4A — and quietly accepts anything else,
 * storing it as a voice note labelled audio/ogg with a duration of 0 that the
 * recipient's app cannot play. Production showed both halves of that: three
 * WebM recordings (one from Chrome, two from the iOS app) all came back with
 * duration 0, and an iPhone that records MP4 was refused at validation because
 * its bytes sniff as video/mp4 even though the composer names the file .m4a.
 */

uses(RefreshDatabase::class);

function tgAudioConversation(): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Telegram,
        'name' => 'Bot',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => '123456:TEST-TOKEN'],
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'name' => 'Maria',
        'external_id' => '4242',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '4242',
        'status' => ConversationStatus::Active,
        'user_id' => $user->id,
    ]);
}

/**
 * Point the SDK at a canned Telegram and keep every request it makes.
 *
 * The SDK brings its own Guzzle client, out of reach of Http::fake(); the
 * handler reads an HTTP handler from the container for exactly this.
 */
function tgAudioFakeTelegram(int $responses = 1): ArrayObject
{
    Storage::fake('local');

    $reply = fn () => new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'ok' => true,
        'result' => [
            'message_id' => 77,
            'date' => 1793621514,
            'chat' => ['id' => 4242, 'type' => 'private'],
            'voice' => ['duration' => 4, 'mime_type' => 'audio/ogg', 'file_id' => 'F', 'file_unique_id' => 'U', 'file_size' => 19],
        ],
    ]));

    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler(array_fill(0, $responses, $reply)));
    $stack->push(Middleware::history($history));

    app()->instance(HttpClientInterface::class, new GuzzleHttpClient(new Client(['handler' => $stack])));

    return $history;
}

/** The bodies of every sendVoice request, as sent. */
function tgAudioSentBodies(ArrayObject $history): array
{
    return collect($history->getArrayCopy())
        ->filter(fn (array $transaction) => str_ends_with($transaction['request']->getUri()->getPath(), '/sendVoice'))
        ->map(function (array $transaction) {
            $body = $transaction['request']->getBody();
            $body->rewind();

            return (string) $body;
        })
        ->values()
        ->all();
}

/**
 * An upload with a declared mime and real bytes. The fake reports its declared
 * mime to guessExtension(), which is what `mimes:` and the handler both read —
 * so the mime here stands for what production's libmagic says about the bytes.
 */
function tgAudioFile(string $name, string $mime, string $bytes): UploadedFile
{
    $file = UploadedFile::fake()->create($name, 8, $mime);

    file_put_contents($file->getRealPath(), $bytes);

    return $file;
}

/** A converter that records what it was handed and writes a token Ogg. */
function tgAudioFakeConverter(): AudioNormalizer
{
    $converter = new class extends AudioNormalizer
    {
        public array $converted = [];

        public array $outputs = [];

        public function available(): bool
        {
            return true;
        }

        public function toOggOpus(UploadedFile $file): UploadedFile
        {
            $this->converted[] = $file->getClientOriginalName();

            $path = tempnam(sys_get_temp_dir(), 'fake_opus_');
            file_put_contents($path, 'OggS-converted-opus');
            $this->outputs[] = $path;

            return new UploadedFile(
                $path,
                pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.ogg',
                'audio/ogg',
                null,
                true,
            );
        }
    };

    app()->instance(AudioNormalizer::class, $converter);

    return $converter;
}

test('a WebM recording reaches Telegram as Ogg/Opus, not as the WebM it was recorded in', function () {
    $history = tgAudioFakeTelegram();
    $converter = tgAudioFakeConverter();

    // What Chrome and the iOS app hand back. A WebM container sniffs as
    // *video*/webm even when it holds nothing but a voice note.
    $message = (new MessageService)->sendAudio(tgAudioConversation(), [
        'audio' => tgAudioFile('audio_1793621514288.webm', 'video/webm', "\x1A\x45\xDF\xA3webm-recording"),
    ]);

    $bodies = tgAudioSentBodies($history);

    expect($converter->converted)->toBe(['audio_1793621514288.webm'])
        ->and($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('OggS-converted-opus')
        ->and($bodies[0])->not->toContain('webm-recording');

    // The thread keeps what the customer received, as on WhatsApp.
    expect($message->attachment)->toEndWith('audio_1793621514288.ogg')
        ->and(Storage::disk('local')->get($message->attachment))->toBe('OggS-converted-opus');

    // The converted file is a temp file only this request knew about.
    expect(file_exists($converter->outputs[0]))->toBeFalse();
});

test('an iPhone recording named .m4a but holding MP4 is accepted and converted', function () {
    $history = tgAudioFakeTelegram();
    $converter = tgAudioFakeConverter();

    // The composer names a WebKit MP4 recording .m4a; its bytes sniff as
    // video/mp4, which the rule used to refuse as "must be a file of type:
    // ogg, mp3, wav, m4a, opus, webm".
    (new MessageService)->sendAudio(tgAudioConversation(), [
        'audio' => tgAudioFile('audio_1793621514288.m4a', 'video/mp4', 'ftypmp42-recording'),
    ]);

    expect($converter->converted)->toBe(['audio_1793621514288.m4a'])
        ->and(tgAudioSentBodies($history)[0])->toContain('OggS-converted-opus');
});

test('a format Telegram plays as a voice note is sent untouched, without ffmpeg', function (string $name, string $mime) {
    $history = tgAudioFakeTelegram();

    // The real converter, with no binary behind it: if it were reached, the
    // send would fail. The AI's mp3 voice replies travel this path.
    config(['media.ffmpeg_path' => '/nonexistent/ffmpeg']);

    $message = (new MessageService)->sendAudio(tgAudioConversation(), [
        'audio' => tgAudioFile($name, $mime, 'original-voice-bytes'),
    ]);

    expect(tgAudioSentBodies($history)[0])->toContain('original-voice-bytes')
        ->and($message->attachment)->toEndWith($name)
        ->and(Storage::disk('local')->get($message->attachment))->toBe('original-voice-bytes');
})->with([
    'ogg from Firefox' => ['nota.ogg', 'audio/ogg'],
    'mp3 from the AI' => ['resposta.mp3', 'audio/mpeg'],
    'a real m4a' => ['memo.m4a', 'audio/x-m4a'],
]);

test('without a converter a WebM is refused in our words and Telegram is never called', function () {
    $history = tgAudioFakeTelegram();

    config(['media.ffmpeg_path' => '/nonexistent/ffmpeg']);

    $thrown = null;

    try {
        (new MessageService)->sendAudio(tgAudioConversation(), [
            'audio' => tgAudioFile('nota.webm', 'video/webm', 'webm-recording'),
        ]);
    } catch (UserFacingException $th) {
        $thrown = $th;
    }

    // Sent anyway, it would arrive as a voice note nobody can play — which is
    // worse than a refusal the agent can act on.
    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toBe(AudioNormalizer::FAILURE_MESSAGE)
        ->and($thrown->getErrorCode())->toBe(AudioNormalizer::FAILURE_CODE)
        ->and($thrown->httpStatus())->toBe(422)
        ->and($history)->toHaveCount(0);
});

test('an mp3 URL reaches Telegram as the same mp3, without ffmpeg', function () {
    $history = tgAudioFakeTelegram();

    config(['media.ffmpeg_path' => '/nonexistent/ffmpeg']);

    // An ID3 header: enough for libmagic to call the bytes audio/mpeg. The
    // download is what gets sniffed, not the URL's extension.
    $mp3 = "ID3\x04\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x64" . str_repeat("\x00", 64) . 'mp3-voice';

    Http::fake([
        'cdn.example.com/*' => Http::response($mp3, 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    // ⚠️ It is downloaded even though Telegram could fetch it itself: SDK
    // v3.15 refuses a plain URL string for an upload field before sending
    // anything, so sendMediaByUrl() throws locally and every URL send lands
    // on the download fallback. What this asserts is that the fallback does
    // not convert a file Telegram already plays.
    (new MessageService)->sendAudio(tgAudioConversation(), [
        'media_url' => 'https://cdn.example.com/aviso.mp3',
    ]);

    expect(tgAudioSentBodies($history))->toHaveCount(1)
        ->and(tgAudioSentBodies($history)[0])->toContain('mp3-voice');
});

test('a URL Telegram cannot play is downloaded and converted first', function () {
    $history = tgAudioFakeTelegram();
    $converter = tgAudioFakeConverter();

    Http::fake([
        'cdn.example.com/*' => Http::response('webm-recording', 200, ['Content-Type' => 'video/webm']),
    ]);

    // Handed over as a URL, Telegram would fetch it, accept it, and deliver a
    // voice note nobody can play.
    (new MessageService)->sendAudio(tgAudioConversation(), [
        'media_url' => 'https://cdn.example.com/nota.webm',
    ]);

    $bodies = tgAudioSentBodies($history);

    expect($converter->converted)->toBe(['nota.webm'])
        ->and($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('OggS-converted-opus')
        ->and($bodies[0])->not->toContain('cdn.example.com');
});
