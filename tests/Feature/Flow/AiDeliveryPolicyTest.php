<?php

use App\Enums\Connection\Channel;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Message;
use App\Services\AiAgentHub\AiDeliveryPolicy;
use App\Services\AiAgentHub\AiVoiceReply;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/** decide() with everything quiet, so each test names only what it changes. */
function decide(array $overrides = []): string
{
    $ctx = array_merge([
        'mode' => AiDeliveryPolicy::MODE_DYNAMIC,
        'channel' => Channel::WhatsappOfficial,
        'customerSpoke' => false,
        'voiceRequested' => false,
        'lastReplyWasAudio' => false,
    ], $overrides);

    return AiDeliveryPolicy::decide(
        $ctx['mode'],
        $ctx['channel'],
        $ctx['customerSpoke'],
        $ctx['voiceRequested'],
        $ctx['lastReplyWasAudio'],
    );
}

test('dynamic answers a typed question in writing', function () {
    // Voice is something the customer opens, not something a bot starts doing.
    expect(decide())->toBe(AiDeliveryPolicy::MODE_TEXT_ONLY);
});

test('dynamic answers a voice note out loud', function () {
    expect(decide(['customerSpoke' => true]))->toBe(AiDeliveryPolicy::MODE_AUDIO_ONLY);
});

test('a customer who keeps speaking keeps being spoken to', function () {
    // The alternation rule must not fire here: recording three voice notes in
    // a row is telling you how this person prefers to talk.
    expect(decide(['customerSpoke' => true, 'lastReplyWasAudio' => true]))
        ->toBe(AiDeliveryPolicy::MODE_AUDIO_ONLY);
});

test('a customer who typed after hearing a voice note gets writing back', function () {
    expect(decide(['lastReplyWasAudio' => true]))->toBe(AiDeliveryPolicy::MODE_TEXT_ONLY);
});

test('an explicit request outranks every heuristic', function () {
    expect(decide(['voiceRequested' => true, 'lastReplyWasAudio' => true]))
        ->toBe(AiDeliveryPolicy::MODE_AUDIO_ONLY);
});

test('the fixed modes are not second-guessed', function () {
    expect(decide(['mode' => AiDeliveryPolicy::MODE_AUDIO_ONLY, 'lastReplyWasAudio' => true]))
        ->toBe(AiDeliveryPolicy::MODE_AUDIO_ONLY)
        ->and(decide(['mode' => AiDeliveryPolicy::MODE_TEXT_AND_AUDIO]))
        ->toBe(AiDeliveryPolicy::MODE_TEXT_AND_AUDIO)
        ->and(decide(['mode' => AiDeliveryPolicy::MODE_TEXT_ONLY, 'customerSpoke' => true, 'voiceRequested' => true]))
        ->toBe(AiDeliveryPolicy::MODE_TEXT_ONLY);
});

test('an answer nobody could act on by ear gets the text as well', function (string $reply, bool $speakable) {
    expect(AiDeliveryPolicy::speakable($reply))->toBe($speakable);

    expect(AiDeliveryPolicy::reconsider(AiDeliveryPolicy::MODE_AUDIO_ONLY, $reply, false))
        ->toBe($speakable ? AiDeliveryPolicy::MODE_AUDIO_ONLY : AiDeliveryPolicy::MODE_TEXT_AND_AUDIO);
})->with([
    // Things that have to be copied. Hearing them is being asked to take
    // dictation, which is not the same as being told.
    ['O host é proxy.ipbr.pro:8080, usuário: cliente01', false],
    ['Acesse https://painel.ipbr.pro para ver seus proxies', false],
    ['Seu IP é 187.45.201.10', false],
    ['A senha: X7kd92Lm', false],
    ["Faça assim:\n1. Abra o painel\n2. Clique em Proxies\n3. Copie o host", false],
    [str_repeat('Explicando com calma. ', 40), false],
    // Things a person can simply hear and answer.
    ['Consigo sim, o IPv6 resolve esse caso. Quer que eu prepare um teste?', true],
    ['Bom dia! Tudo certo por aqui, como posso ajudar?', true],
]);

test('a handoff is always put in writing', function () {
    // The moment to be unambiguous, and the note stays readable in the thread
    // for whoever picks the conversation up.
    expect(AiDeliveryPolicy::reconsider(AiDeliveryPolicy::MODE_AUDIO_ONLY, 'Vou te transferir agora.', true))
        ->toBe(AiDeliveryPolicy::MODE_TEXT_AND_AUDIO);
});

test('reconsidering never takes the voice away', function () {
    // It is already generated and already paid for, and the customer asked to
    // be spoken to — the written half is added, never swapped in.
    $final = AiDeliveryPolicy::reconsider(AiDeliveryPolicy::MODE_AUDIO_ONLY, 'host: proxy.ipbr.pro:8080', false);

    expect(AiDeliveryPolicy::speaks($final))->toBeTrue()
        ->and(AiDeliveryPolicy::writes($final))->toBeTrue();
});

test('a node saved before the modes existed keeps working', function () {
    // Translated, not migrated: a flow that survives a redesign is worth more
    // than a tidy column.
    expect(AiVoiceReply::config(['response_audio' => ['enabled' => false]])['mode'])
        ->toBe(AiDeliveryPolicy::MODE_TEXT_ONLY)
        ->and(AiVoiceReply::config(['response_audio' => ['enabled' => true, 'mode' => 'match_customer']])['mode'])
        ->toBe(AiDeliveryPolicy::MODE_DYNAMIC)
        ->and(AiVoiceReply::config(['response_audio' => ['enabled' => true, 'mode' => 'always']])['mode'])
        ->toBe(AiDeliveryPolicy::MODE_AUDIO_ONLY)
        ->and(AiVoiceReply::config([
            'response_audio' => ['enabled' => true, 'mode' => 'always', 'delivery' => 'audio_and_text'],
        ])['mode'])->toBe(AiDeliveryPolicy::MODE_TEXT_AND_AUDIO)
        // No audio settings at all: a flow never starts speaking on its own.
        ->and(AiVoiceReply::config([])['mode'])->toBe(AiDeliveryPolicy::MODE_TEXT_ONLY);
});

test('end to end: the voice note is answered out loud, and the credentials are written down', function () {
    Storage::fake('local', ['serve' => true]);

    Http::fake([
        'api-ia.ipbr.pro/v1/media/*' => Http::response(AiAgentFixtures::opusBytes(), 200, ['Content-Type' => 'audio/ogg']),
        'graph.facebook.com/*/media' => Http::response(['id' => 'wa-media-1']),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT' . uniqid()]]]),
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_1',
            'status' => 'COMPLETED',
            'output' => [
                'message' => 'Claro: use o host proxy.ipbr.pro:8080 com o usuário cliente01.',
                'handoff' => false,
                'audio' => [
                    'status' => 'generated',
                    'url' => 'https://api-ia.ipbr.pro/v1/media/audio/eyJ0eXAiOiJhdWRpbyJ9',
                    'mimeType' => 'audio/ogg',
                    'format' => 'opus',
                ],
            ],
        ]),
    ]);

    [$conversation, $node] = AiAgentFixtures::flow();
    $node->update(['data' => array_merge($node->data, [
        'response_audio' => ['mode' => AiDeliveryPolicy::MODE_DYNAMIC],
    ])]);
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/51_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    // Asked for out loud because they spoke; written as well because the
    // answer turned out to carry a host, a port and a login.
    expect(AiAgentFixtures::hubRuns()[0])->toHaveKey('responseAudio');

    $sent = Message::where('sender_type', SenderType::Outgoing)
        ->whereIn('message_type', [MessageType::Text, MessageType::Audio])
        ->orderBy('id')
        ->get()
        // Past the canned greeting the node sends on the way in.
        ->skip(1)
        ->values();

    expect($sent->pluck('message_type')->map->value->all())
        ->toBe([MessageType::Text->value, MessageType::Audio->value]);
});
