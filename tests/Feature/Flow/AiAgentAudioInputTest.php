<?php

use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Http\Resources\MessageResource;
use App\Jobs\DownloadInboundMedia;
use App\Models\AiHubRun;
use App\Models\FlowState;
use App\Models\Message;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/** The hub's reply when it was also asked to transcribe. */
function hubAnswerWithTranscript(string $said, string $reply = 'Vou verificar seu proxy.'): array
{
    return [
        'message' => $reply,
        'handoff' => false,
        'inputAudio' => [
            'transcribed' => true,
            'items' => [[
                'text' => $said,
                'model' => 'gpt-4o-mini-transcribe',
                'language' => 'pt',
                'source' => 'url',
            ]],
        ],
    ];
}

test('a voice note reaches the agent as an audio attachment the hub is asked to transcribe', function () {
    // The real disk on purpose: a faked one signs URLs differently, and the
    // signature is the whole reason the hub can fetch the file at all.
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/21_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $run = AiAgentFixtures::hubRuns()[0];
    $attachment = $run['message']['attachments'][0] ?? null;

    expect($attachment['type'])->toBe('audio')
        ->and($attachment['mimeType'])->toBe('audio/ogg')
        ->and($attachment['name'])->toBe('21_abc.ogg')
        // The hub fetches this itself, so it has to be a signed link and not a
        // storage path only this app can read.
        ->and($attachment['url'])->toContain('media/21_abc.ogg')
        ->and($attachment['url'])->toContain('signature=');

    // Without this block the file travels and is ignored: the hub transcribes
    // only when asked, and a voice note nobody transcribed is not input.
    expect($run['inputAudio']['transcriptionModel'])->toBe('gpt-4o-mini-transcribe')
        ->and($run['inputAudio']['language'])->toBe('pt');

    expect(AiHubRun::first()->metadata['audioAttachments'])->toBe(1);
});

test('what the hub heard is written back onto the voice note', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(
        output: hubAnswerWithTranscript('Meu proxy não está conectando, pode verificar?'),
    );

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    $audio = AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/22_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    // The run paid for these words either way. Keeping them is what lets the
    // human who takes the thread over read it instead of listening to it.
    $transcription = $audio->fresh()->meta['transcription'];

    expect($transcription['text'])->toBe('Meu proxy não está conectando, pode verificar?')
        ->and($transcription['model'])->toBe('gpt-4o-mini-transcribe')
        ->and($transcription['ai_hub_run_id'])->toBe(AiHubRun::first()->id);

    // And it reaches the SPA, on a channel that has no meta of its own to
    // carry it.
    $payload = (new MessageResource($audio->fresh()))->resolve();
    expect($payload['meta']['transcription']['text'])->toBe('Meu proxy não está conectando, pode verificar?');
});

test('the turn is held back while the voice note is still downloading', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation, $node] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    $audio = AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, null, AttachmentStatus::Pending);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    // Stronger than the image case: a voice note carries no text at all, so
    // answering now is not answering half the message — it is answering none.
    expect(AiAgentFixtures::hubRuns())->toBeEmpty();

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBeLessThan($audio->id);

    // The file lands, and the same message is picked up where it was left.
    $audio->forceFill(['attachment' => 'media/23_abc.ogg', 'attachment_status' => null])->save();
    (new FlowExecutor)->resumeAfterMedia($audio->fresh());

    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['attachments'])->toHaveCount(1)
        ->and($runs[0]['message']['attachments'][0]['type'])->toBe('audio');
});

test('a download that never lands still gets the customer an answer', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub(aiReply: 'Pode escrever o que aconteceu?');

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    $audio = AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, null, AttachmentStatus::Pending);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');
    expect(AiAgentFixtures::hubRuns())->toBeEmpty();

    (new DownloadInboundMedia($audio))->failed(new RuntimeException('out of attempts'));

    // Deaf, but not silent: the agent is told a voice note happened and can
    // ask for it in writing, which beats leaving the customer with nothing.
    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe('[audio]')
        ->and($runs[0]['message'])->not->toHaveKey('attachments')
        ->and($runs[0])->not->toHaveKey('inputAudio');

    expect(Message::where('sender_type', SenderType::Outgoing)->pluck('body')->all())
        ->toContain('Pode escrever o que aconteceu?');
});

test('a voice note the transcription models cannot open is announced, not attached', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    // AMR: still delivered by old handsets, rejected by the transcription API
    // on the extension alone. Attaching it buys a failed run and the same
    // "[audio]" the customer gets here for free.
    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/24_abc.amr');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $run = AiAgentFixtures::hubRuns()[0];

    expect($run['message']['content'])->toBe('[audio]')
        ->and($run['message'])->not->toHaveKey('attachments')
        ->and($run)->not->toHaveKey('inputAudio');
});

test('a forwarded recording is described rather than transcribed by the minute', function () {
    Storage::fake('local', ['serve' => true]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    $audio = AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/25_abc.mp3');
    $audio->forceFill(['attachment_size' => 40 * 1024 * 1024])->save();

    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    expect(AiAgentFixtures::hubRuns()[0]['message'])->not->toHaveKey('attachments');
});

test('the kill switch stops the file travelling and stops the turn waiting for it', function () {
    Storage::fake('local', ['serve' => true]);
    config(['ai.audio.enabled' => false]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    // Still downloading: with audio off this must not park the conversation
    // waiting for a file that would be dropped on arrival anyway.
    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, null, AttachmentStatus::Pending);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $run = AiAgentFixtures::hubRuns()[0];

    expect($run['message']['content'])->toBe('[audio]')
        ->and($run['message'])->not->toHaveKey('attachments');
});

test('a burst of voice notes travels whole, up to the per-turn ceiling', function () {
    Storage::fake('local', ['serve' => true]);
    config(['ai.audio.max_per_run' => 2]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);

    foreach (['a', 'b', 'c'] as $index => $suffix) {
        AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, "media/26_{$suffix}.ogg");
    }

    // One turn for the burst, and the newest two recordings are the ones that
    // survive the cap — transcription is billed by the minute.
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe("[audio]\n[audio]\n[audio]");

    expect(array_column($runs[0]['message']['attachments'], 'name'))
        ->toBe(['26_b.ogg', '26_c.ogg']);
});
