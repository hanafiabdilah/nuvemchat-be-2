<?php

use App\Enums\Message\MessageType;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiVocabulary;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/** Park the workspace's vocabulary on the tenant behind a conversation. */
function giveVocabulary(Conversation $conversation, array $terms): Tenant
{
    $tenant = $conversation->connection->tenant;
    $tenant->forceFill(['audio_dictionary' => $terms])->save();

    return $tenant;
}

test("the workspace's own terms travel with a voice note, on top of the platform's", function () {
    Storage::fake('local', ['serve' => true]);
    config(['ai.audio.keyterms' => ['ProxyBR']]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation, $node] = AiAgentFixtures::flow();
    $node->update(['data' => array_merge($node->data, [
        'input_audio' => ['provider' => 'elevenlabs', 'credential_id' => 'cred_1'],
    ])]);

    giveVocabulary($conversation, [
        ['term' => 'SOCKS5', 'aliases' => ['socks 5', 'socks five']],
        ['term' => 'IPv6', 'aliases' => ['IPV6']],
    ]);

    AiAgentFixtures::openWithWelcome($conversation);
    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/v1.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    // Platform first, then the workspace's list in the order it was entered —
    // terms and their aliases, because the alias is the spelling the model
    // actually produces.
    expect(AiAgentFixtures::hubRuns()[0]['inputAudio']['keyterms'])
        ->toBe(['ProxyBR', 'SOCKS5', 'socks 5', 'socks five', 'IPv6', 'IPV6']);
});

test('the same vocabulary reaches OpenAI, which is the default provider and takes a sentence', function () {
    Storage::fake('local', ['serve' => true]);
    config(['ai.audio.keyterms' => [], 'ai.audio.prompt' => null]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation] = AiAgentFixtures::flow();

    giveVocabulary($conversation, [
        ['term' => 'SOCKS5', 'aliases' => ['socks five']],
        ['term' => 'ProxyBR', 'aliases' => []],
    ]);

    AiAgentFixtures::openWithWelcome($conversation);
    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/v2.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $block = AiAgentFixtures::hubRuns()[0]['inputAudio'];

    // A node that chose nothing gets the platform default, which is OpenAI —
    // so without this the dictionary would be a setting most workspaces could
    // fill in and never hear. Aliases stay out: the prompt is short by design.
    expect($block)->not->toHaveKey('keyterms')
        ->and($block['prompt'])->toBe('Termos usados nesta conversa: SOCKS5, ProxyBR.');
});

test('a workspace with no vocabulary sends no hint at all', function () {
    Storage::fake('local', ['serve' => true]);
    config(['ai.audio.keyterms' => [], 'ai.audio.prompt' => null]);
    AiAgentFixtures::fakeChannelsAndHub();

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::openWithWelcome($conversation);
    AiAgentFixtures::incomingMedia($conversation, MessageType::Audio, null, 'media/v3.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    // An empty `prompt` is a field the hub would have to reject, not a hint.
    expect(AiAgentFixtures::hubRuns()[0]['inputAudio'])->not->toHaveKey('prompt');
});

test('malformed rows are dropped rather than repaired', function () {
    expect(AiVocabulary::sanitize([
        ['term' => '  SOCKS5  ', 'aliases' => ['socks 5', 'socks 5', 'x', 'SOCKS5']],
        ['term' => 'socks5'],                       // same word, different case
        ['term' => 'a'],                            // too short to listen for
        ['term' => str_repeat('x', 51)],            // a sentence, not a term
        ['aliases' => ['orphan']],                  // no term
        'ProxyBR',                                  // a bare string still works
    ]))->toBe([
        // Whitespace collapsed, aliases deduped, the one-character alias and
        // the alias that merely repeats the term both gone.
        ['term' => 'SOCKS5', 'aliases' => ['socks 5']],
        ['term' => 'ProxyBR', 'aliases' => []],
    ]);
});

test('the caps cannot push the list past what the hub accepts', function () {
    $terms = [];

    for ($i = 0; $i < AiVocabulary::MAX_TERMS + 20; $i++) {
        $terms[] = [
            'term' => "termo{$i}",
            'aliases' => array_map(fn ($n) => "termo{$i}a{$n}", range(1, AiVocabulary::MAX_ALIASES + 5)),
        ];
    }

    // Unsaved: the caps are a property of the reader, not of the row.
    $tenant = new Tenant(['audio_dictionary' => $terms]);

    expect(AiVocabulary::dictionary($tenant))->toHaveCount(AiVocabulary::MAX_TERMS)
        ->and(AiVocabulary::dictionary($tenant)[0]['aliases'])->toHaveCount(AiVocabulary::MAX_ALIASES)
        // 100 × (1 + 8) = 900, comfortably under the hub's 1000.
        ->and(count(AiVocabulary::keyterms($tenant)))->toBeLessThan(1000);
});
