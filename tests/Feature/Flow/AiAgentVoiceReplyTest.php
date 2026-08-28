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
 * Enough of a real MP3 for the `mimes:` rule on the send path: the validator
 * guesses the type from the bytes, and "fake-audio" guesses as plain text.
 */
function mp3Bytes(): string
{
    return "ID3\x04\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 32);
}

/** The hub's reply when it was asked to speak. */
function hubSpokenAnswer(string $status = 'generated'): array
{
    return [
        'audio' => array_filter([
            'enabled' => true,
            'status' => $status,
            'url' => $status === 'generated' ? 'https://api-ia.ipbr.pro/v1/media/audio/eyJ0eXAiOiJhdWRpbyJ9' : null,
            'mimeType' => 'audio/mpeg',
            'format' => 'mp3',
            'voice' => 'onyx',
            'expiresAt' => '2026-08-29T12:00:00.000Z',
        ], fn ($v) => $v !== null),
    ];
}

/** WhatsApp media upload + the hub's audio file, ahead of the catch-all fakes. */
function voiceReplyFakes(): array
{
    return [
        'api-ia.ipbr.pro/v1/media/*' => Http::response(mp3Bytes(), 200, ['Content-Type' => 'audio/mpeg']),
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
        ->and($run['responseAudio']['format'])->toBe('mp3');

    $voice = outgoing(MessageType::Audio);

    expect($voice)->toHaveCount(1)
        // Our own copy of the file, not the hub's expiring link.
        ->and($voice->first()->attachment)->toStartWith('media/')
        ->and($voice->first()->attachment)->not->toContain('api-ia.ipbr.pro')
        // The words are kept so the thread stays readable for a human.
        ->and($voice->first()->meta['transcription']['text'])->toBe('Consigo sim, o IPv6 resolve esse caso.')
        ->and($voice->first()->meta['ai_generated'])->toBeTrue();

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
