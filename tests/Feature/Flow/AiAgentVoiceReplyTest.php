<?php

use App\Enums\Connection\Channel;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Services\AiAgentHub\AiVoiceReply;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/**
 * Enough of a real Ogg/Opus file for the `mimes:` rule on the send path: the
 * validator guesses the type from the bytes, and "fake-audio" guesses as plain
 * text. A first page carrying the OpusHead packet is all libmagic reads.
 */
function opusBytes(): string
{
    $body = "OpusHead" . pack("Cx", 1) . pack("v", 312) . pack("V", 48000) . pack("v", 0) . "\x00\x00";

    $header = "OggS" . chr(0) . chr(2) . str_repeat("\x00", 8)
        . pack("V", 12345) . pack("V", 0) . pack("V", 0)
        . chr(1) . chr(strlen($body));

    return $header . $body . str_repeat("\x00", 32);
}

/** The hub's reply when it was asked to speak. */
function hubSpokenAnswer(string $status = 'generated'): array
{
    return [
        'audio' => array_filter([
            'enabled' => true,
            'status' => $status,
            'url' => $status === 'generated' ? 'https://api-ia.ipbr.pro/v1/media/audio/eyJ0eXAiOiJhdWRpbyJ9' : null,
            'mimeType' => 'audio/ogg',
            'format' => 'opus',
            'voice' => 'onyx',
            'expiresAt' => '2026-08-29T12:00:00.000Z',
        ], fn ($v) => $v !== null),
    ];
}

/** WhatsApp media upload + the hub's audio file, ahead of the catch-all fakes. */
function voiceReplyFakes(): array
{
    return [
        'api-ia.ipbr.pro/v1/media/*' => Http::response(opusBytes(), 200, ['Content-Type' => 'audio/ogg']),
        'graph.facebook.com/*/media' => Http::response(['id' => 'wa-media-1']),
    ];
}

/** Turn the fixture's AI node into one configured to speak. */
function speakingNode(FlowNode $node, array $settings = []): FlowNode
{
    $node->update([
        'data' => array_merge($node->data ?? [], [
            'response_audio' => array_merge(['enabled' => true], $settings),
        ]),
    ]);

    return $node->refresh();
}

/** The audio object of the last WhatsApp message send. */
function lastWhatsappAudioPayload(): array
{
    $payload = [];

    foreach (Http::recorded() as [$request, $response]) {
        if (str_ends_with($request->url(), '/messages') && ($request->data()['type'] ?? null) === 'audio') {
            $payload = $request->data()['audio'] ?? [];
        }
    }

    return $payload;
}

function outgoing(MessageType $type): \Illuminate\Support\Collection
{
    return Message::where('sender_type', SenderType::Outgoing)
        ->where('message_type', $type)
        ->get();
}

test('a customer who sends a voice note is answered with one', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(
        aiReply: 'Consigo sim, o IPv6 resolve esse caso.',
        extra: voiceReplyFakes(),
        output: hubSpokenAnswer(),
    );

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/31_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    // Asked for with the run: the voice is generated alongside the text, so a
    // request that leaves this out can never be spoken.
    $run = AiAgentFixtures::hubRuns()[0];
    expect($run['responseAudio']['enabled'])->toBeTrue()
        ->and($run['responseAudio']['model'])->toBe('gpt-4o-mini-tts')
        // Opus, not MP3: on WhatsApp the format is what decides between a
        // voice note and a file attachment somebody has to open.
        ->and($run['responseAudio']['format'])->toBe('opus');

    $voice = outgoing(MessageType::Audio);

    expect($voice)->toHaveCount(1)
        // Our own copy of the file, not the hub's expiring link — and stored
        // as .ogg, which is what both WhatsApp handlers pass through untouched.
        ->and($voice->first()->attachment)->toStartWith('media/')
        ->and($voice->first()->attachment)->toEndWith('.ogg')
        ->and($voice->first()->attachment)->not->toContain('api-ia.ipbr.pro')
        // The words are kept so the thread stays readable for a human.
        ->and($voice->first()->meta['transcription']['text'])->toBe('Consigo sim, o IPv6 resolve esse caso.')
        ->and($voice->first()->meta['ai_generated'])->toBeTrue();

    // The codec alone does not make a voice note: without this flag WhatsApp
    // draws a file attachment, whatever the file happens to be.
    expect(lastWhatsappAudioPayload()['voice'])->toBeTrue();

    // Only the greeting was ever written; the answer itself was spoken.
    expect(outgoing(MessageType::Text)->pluck('body')->all())->toBe(['Oi! Como posso ajudar?']);
});

test('a customer who asks for audio in writing gets audio', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(extra: voiceReplyFakes(), output: hubSpokenAnswer());

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node);
    AiAgentFixtures::openWithWelcome($conversation);

    $conversation->messages()->create([
        'external_id' => 'wamid.ask',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Pode me responder por áudio?',
        'sent_at' => now(),
    ]);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'Pode me responder por áudio?');

    expect(AiAgentFixtures::hubRuns()[0])->toHaveKey('responseAudio')
        ->and(outgoing(MessageType::Audio))->toHaveCount(1);
});

test('the request sticks for the turns that follow, and one sentence takes it back', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(extra: voiceReplyFakes(), output: hubSpokenAnswer());

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node);
    AiAgentFixtures::openWithWelcome($conversation);

    $say = function (string $body) use ($conversation) {
        $conversation->messages()->create([
            'external_id' => 'wamid.' . uniqid(),
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => $body,
            'sent_at' => now(),
        ]);

        (new FlowExecutor)->resumeFlow($conversation->fresh(), $body);
    };

    $say('Me manda um áudio explicando');
    // Nothing about audio in this one: the instruction was about the
    // conversation, not about the message it arrived in.
    $say('E quanto custa o plano mensal?');

    $runs = AiAgentFixtures::hubRuns();
    expect($runs[0])->toHaveKey('responseAudio')
        ->and($runs[1])->toHaveKey('responseAudio');

    $say('Prefiro texto, pode escrever');

    expect(AiAgentFixtures::hubRuns()[2])->not->toHaveKey('responseAudio');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_voice_{$node->id}"])->toBeFalse();
});

test('a node nobody configured stays silent, however the customer writes', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(extra: voiceReplyFakes(), output: hubSpokenAnswer());

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    // Both triggers at once, and neither counts while the node is off: a flow
    // does not start speaking because a customer asked it to.
    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, 'me responde em áudio', 'media/32_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'me responde em áudio');

    expect(AiAgentFixtures::hubRuns()[0])->not->toHaveKey('responseAudio')
        ->and(outgoing(MessageType::Audio))->toBeEmpty();
});

test('always mode speaks to a customer who only ever typed', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(extra: voiceReplyFakes(), output: hubSpokenAnswer());

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node, ['mode' => AiVoiceReply::MODE_ALWAYS, 'voice' => 'alloy', 'speed' => 1.18]);
    AiAgentFixtures::openWithWelcome($conversation);

    $conversation->messages()->create([
        'external_id' => 'wamid.typed',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Bom dia',
        'sent_at' => now(),
    ]);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'Bom dia');

    $run = AiAgentFixtures::hubRuns()[0];

    // The node's own voice wins over the platform default.
    expect($run['responseAudio']['voice'])->toBe('alloy')
        ->and($run['responseAudio']['speed'])->toBe(1.18)
        ->and(outgoing(MessageType::Audio))->toHaveCount(1);
});

test('audio and text together sends the written answer first', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(
        aiReply: 'O host é proxy.ipbr.pro na porta 8080.',
        extra: voiceReplyFakes(),
        output: hubSpokenAnswer(),
    );

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node, ['delivery' => AiVoiceReply::DELIVERY_AUDIO_AND_TEXT]);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/33_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    // A host and a port are unusable from a voice note; the writing goes out
    // first so it is there to copy.
    $turn = Message::where('sender_type', SenderType::Outgoing)
        ->whereIn('message_type', [MessageType::Text, MessageType::Audio])
        ->orderBy('id')
        ->get()
        // Past the canned greeting the node sends on the way in.
        ->skip(1)
        ->values();

    expect($turn->pluck('message_type')->map->value->all())
        ->toBe([MessageType::Text->value, MessageType::Audio->value])
        ->and($turn->first()->body)->toBe('O host é proxy.ipbr.pro na porta 8080.')
        ->and($turn->last()->meta['transcription']['text'])->toBe('O host é proxy.ipbr.pro na porta 8080.');
});

test('a voice the hub could not produce still gets the customer an answer', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(
        aiReply: 'Claro, posso ajudar.',
        extra: voiceReplyFakes(),
        output: hubSpokenAnswer(status: 'failed'),
    );

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/34_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    expect(outgoing(MessageType::Audio))->toBeEmpty()
        ->and(outgoing(MessageType::Text)->pluck('body')->all())->toContain('Claro, posso ajudar.');
});

test('a file that will not download falls back to the words it was made from', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(
        aiReply: 'Claro, posso ajudar.',
        extra: ['api-ia.ipbr.pro/v1/media/*' => Http::response('gone', 404)] + voiceReplyFakes(),
        output: hubSpokenAnswer(),
    );

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/35_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    expect(outgoing(MessageType::Audio))->toBeEmpty()
        ->and(outgoing(MessageType::Text)->pluck('body')->all())->toContain('Claro, posso ajudar.');
});

test('a channel that cannot carry a voice note is never asked to', function () {
    $config = AiVoiceReply::config(['response_audio' => ['enabled' => true, 'mode' => AiVoiceReply::MODE_ALWAYS]]);

    expect(AiVoiceReply::shouldSpeak($config, Channel::WhatsappOfficial, false, false))->toBeTrue()
        // Text and image only — an unplayable file is worse than a written reply.
        ->and(AiVoiceReply::shouldSpeak($config, Channel::TikTok, true, true))->toBeFalse()
        // An MP3 attached to a mail is not somebody answering you.
        ->and(AiVoiceReply::shouldSpeak($config, Channel::Email, true, true))->toBeFalse();
});

test('the platform kill switch outranks every node', function () {
    config(['ai.voice.enabled' => false]);

    $config = AiVoiceReply::config(['response_audio' => ['enabled' => true, 'mode' => AiVoiceReply::MODE_ALWAYS]]);

    expect($config['enabled'])->toBeFalse()
        ->and(AiVoiceReply::shouldSpeak($config, Channel::WhatsappOfficial, true, true))->toBeFalse();
});

test('reading a customer message as an instruction about the medium', function (string $text, ?bool $expected) {
    expect(AiVoiceReply::requestSignal($text))->toBe($expected);
})->with([
    ['Me manda um áudio por favor', true],
    ['pode responder em audio?', true],
    ['prefiro áudio', true],
    ['Send me an audio please', true],
    ['prefiro texto', false],
    ['não manda áudio', false],
    ['pode parar de mandar áudio', false],
    ['não consigo ouvir áudio agora', false],
    // The word alone is the customer talking about what they just sent.
    ['acabei de mandar, recebeu meu áudio?', null],
    ['quanto custa o plano mensal?', null],
    ['', null],
]);

test('a node can speak through ElevenLabs, in ElevenLabs\' own spelling', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(extra: voiceReplyFakes(), output: hubSpokenAnswer());

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node, [
        'provider' => 'elevenlabs',
        'credential_id' => 'cred_11labs_1',
        'voice_id' => 'v0iceId11labs',
        'speed' => 1.2,
        'voice_settings' => [
            'stability' => 0.45,
            'similarity_boost' => 0.8,
            'style' => 0.2,
            'use_speaker_boost' => true,
        ],
    ]);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/36_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $block = AiAgentFixtures::hubRuns()[0]['responseAudio'];

    expect($block['provider'])->toBe('ELEVENLABS')
        ->and($block['providerCredentialId'])->toBe('cred_11labs_1')
        ->and($block['model'])->toBe('eleven_flash_v2_5')
        ->and($block['voiceId'])->toBe('v0iceId11labs')
        // The channel still decides the format; only its spelling changes.
        ->and($block['outputFormat'])->toBe('opus_48000_32')
        ->and($block['voiceSettings'])->toBe([
            'stability' => 0.45,
            'similarityBoost' => 0.8,
            'style' => 0.2,
            'useSpeakerBoost' => true,
        ])
        // OpenAI's fields would be meaningless here, and the hub validates.
        ->and($block)->not->toHaveKey('voice')
        ->and($block)->not->toHaveKey('format')
        ->and($block)->not->toHaveKey('instructions');

    expect(outgoing(MessageType::Audio))->toHaveCount(1);
});

test('ElevenLabs without a voice id speaks with OpenAI, and leaves its fields behind', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(extra: voiceReplyFakes(), output: hubSpokenAnswer());

    [$conversation, $node] = AiAgentFixtures::flow();
    // A voice there is an id from somebody's own account; there is nothing to
    // fall back to, and the author still asked for audio.
    speakingNode($node, [
        'provider' => 'elevenlabs',
        'model' => 'eleven_flash_v2_5',
        'credential_id' => 'cred_11labs_1',
        'voice_settings' => ['stability' => 0.4],
    ]);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/37_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $block = AiAgentFixtures::hubRuns()[0]['responseAudio'];

    // An ElevenLabs model handed to OpenAI is not a model, and its credential
    // is not a credential — the hub answers that mixture with
    // "credential not found", which reads like a broken account.
    expect($block['provider'])->toBe('OPENAI')
        ->and($block['voice'])->toBe('onyx')
        ->and($block['model'])->toBe('gpt-4o-mini-tts')
        ->and($block)->not->toHaveKey('providerCredentialId')
        ->and($block)->not->toHaveKey('voiceSettings')
        ->and($block)->not->toHaveKey('voiceId')
        ->and(outgoing(MessageType::Audio))->toHaveCount(1);
});

test('an API key pasted where a credential id belongs is dropped, not forwarded', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(extra: voiceReplyFakes(), output: hubSpokenAnswer());

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node, [
        'provider' => 'elevenlabs',
        'voice_id' => 'v0iceId11labs',
        // The key is the thing a person is holding, so it is the thing they
        // paste. Forwarding it buys "credential not found" and puts a secret
        // in a field never meant to hold one.
        'credential_id' => 'sk_9482b8bfbcf2dd322a7054b00eaec04a542915560fe948ef',
    ]);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/38_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $block = AiAgentFixtures::hubRuns()[0]['responseAudio'];

    // Left out, so the hub falls back to the account's default credential.
    expect($block['provider'])->toBe('ELEVENLABS')
        ->and($block)->not->toHaveKey('providerCredentialId');
});

test('the stored file is named after what actually arrived, in either dialect', function (string $format, ?string $mime, string $expected) {
    expect(AiVoiceReply::extensionFor($format, $mime))->toBe($expected);
})->with([
    // OpenAI's spelling, ElevenLabs' spelling, and the MIME winning over both.
    ['opus', null, 'ogg'],
    ['opus_48000_32', null, 'ogg'],
    ['mp3_44100_128', null, 'mp3'],
    ['mp3', 'audio/ogg', 'ogg'],
    ['opus', 'audio/mpeg', 'mp3'],
    ['something-new', null, 'mp3'],
]);

test('a run the hub failed is retried without the audio, not left in silence', function () {
    Storage::fake('local', ['serve' => true]);

    // How this reached production: the hub answers 200, the run inside it is
    // FAILED, and `output` is null — so nothing throws, nothing is sent, and
    // the customer waits for a bot that already gave up.
    Http::fake([
        'api-ia.ipbr.pro/v1/media/*' => Http::response(opusBytes(), 200, ['Content-Type' => 'audio/ogg']),
        'graph.facebook.com/*/media' => Http::response(['id' => 'wa-media-1']),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
        'api-ia.ipbr.pro/*' => Http::sequence()
            ->push([
                'id' => 'run_failed',
                'status' => 'FAILED',
                'output' => null,
                'error' => ['name' => 'Error', 'message' => 'ElevenLabs transcription failed: 401 missing_permissions'],
            ])
            ->push([
                'id' => 'run_ok',
                'status' => 'COMPLETED',
                'output' => ['message' => 'Claro, posso ajudar.', 'handoff' => false],
            ]),
    ]);

    [$conversation, $node] = AiAgentFixtures::flow();
    speakingNode($node, ['provider' => 'elevenlabs', 'voice_id' => 'v0iceId11labs']);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/39_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $runs = AiAgentFixtures::hubRuns();

    expect($runs)->toHaveCount(2)
        ->and($runs[0])->toHaveKey('responseAudio')
        // Second attempt keeps the words and drops everything optional.
        ->and($runs[1])->not->toHaveKey('responseAudio')
        ->and($runs[1])->not->toHaveKey('inputAudio')
        ->and($runs[1]['message'])->not->toHaveKey('attachments');

    expect(Message::where('sender_type', SenderType::Outgoing)->pluck('body')->all())
        ->toContain('Claro, posso ajudar.');
});

test('a run that fails with nothing left to blame is routed away, not left hanging', function () {
    Storage::fake('local', ['serve' => true]);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_failed',
            'status' => 'FAILED',
            'output' => null,
            'error' => ['message' => 'the model is on fire'],
        ]),
    ]);

    [$conversation, $node] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    $conversation->messages()->create([
        'external_id' => 'wamid.typed',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Bom dia',
        'sent_at' => now(),
    ]);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'Bom dia');

    // Where it goes from here is the node's own handoff setting; what matters
    // is that the failure is routed at all. Before this, an empty reply left
    // the turn parked on the node and the customer waiting on a bot that had
    // already given up.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_handoff_reason_{$node->id}"])->toBe('error');

    // And nothing was invented to fill the silence.
    expect(Message::where('sender_type', SenderType::Outgoing)->where('message_type', MessageType::Text)->count())
        ->toBe(1);
});
