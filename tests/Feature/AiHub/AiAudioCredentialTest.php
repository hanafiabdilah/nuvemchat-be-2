<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Exceptions\UserFacingException;
use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubTenant;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiAgentHub\AiAudioCredential;
use App\Services\AiAgentHub\AiTranscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreditFixtures;

uses(RefreshDatabase::class);

/*
 * Voice notes are transcribed, and replies spoken, on the workspace's own key.
 *
 * Left without a `providerCredentialId`, the hub picked the newest key of that
 * provider in the one account every workspace shares — another customer's key
 * whenever the agent's own model was not OpenAI.
 */

/**
 * A workspace whose agent runs on `$agentProvider`, plus its own keys.
 *
 * @param  list<array{provider: string, id: string, rented?: bool, needs_key?: bool}>  $keys
 * @return array{0: Tenant, 1: AiHubAgent, 2: Conversation}
 */
function audioKeyWorkspace(string $agentProvider = 'ANTHROPIC', array $keys = [], bool $agentRented = false): array
{
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    $user = User::factory()->create(['email' => 'audio-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $scope = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'external_id' => 'Pingly_'.$tenant->id,
        'name' => 'Pingly_'.$tenant->id,
        'status' => 'ACTIVE',
    ]);

    $pool = $agentRented ? CreditFixtures::poolKey(['provider' => $agentProvider]) : null;

    $agentKey = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $scope->id,
        'ai_token_pool_key_id' => $pool?->id,
        'hub_provider_credential_id' => 'agent-key-'.$tenant->id,
        'provider' => $agentProvider,
        'name' => 'Agente',
        'status' => 'ACTIVE',
    ]);

    foreach ($keys as $key) {
        AiHubProviderCredential::create([
            'ai_hub_tenant_id' => $scope->id,
            'ai_token_pool_key_id' => ($key['rented'] ?? false) ? CreditFixtures::poolKey(['provider' => $key['provider']])->id : null,
            'hub_provider_credential_id' => $key['id'],
            'provider' => $key['provider'],
            'name' => $key['id'],
            'status' => 'ACTIVE',
            'metadata' => ($key['needs_key'] ?? false) ? ['needs_key' => true] : null,
        ]);
    }

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $scope->id,
        'ai_hub_provider_credential_id' => $agentKey->id,
        'hub_agent_id' => 'agent-'.$tenant->id,
        'external_id' => 'Pingly_'.$scope->id.'_atendimento',
        'name' => 'Atendimento',
        'model' => $agentProvider === 'OPENAI' ? 'gpt-4o-mini' : 'claude-sonnet-4',
        'status' => 'ACTIVE',
    ]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'credentials' => ['phone_number_id' => '1083508778182246', 'access_token' => 'wa-token'],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'external_id' => '55119'.random_int(10000000, 99999999),
        'name' => 'Ana',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
    ]);

    return [$tenant, $agent, $conversation];
}

/** @return list<array<string, mixed>> */
function audioKeyVoiceNote(): array
{
    return [[
        'type' => 'audio',
        'url' => 'https://chat.pingly.com.br/storage/media/1/nota.ogg?signature=x',
        'name' => 'nota.ogg',
        'mimeType' => 'audio/ogg',
    ]];
}

function audioKeyFakeHub(): void
{
    Http::fake([
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_'.uniqid(),
            'status' => 'COMPLETED',
            'output' => ['message' => 'Entendi o seu áudio.', 'handoff' => false],
        ]),
    ]);
}

/** The run bodies the hub received. */
function audioKeyRuns(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/runs'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

/** "Suggest reply" on a voice note: the call that failed from 13 Nov 2026. */
function audioKeySuggest(AiHubAgent $agent, Conversation $conversation, Tenant $tenant): void
{
    app(AiAgentHubTenantService::class)->runAgent(
        $agent,
        $conversation,
        '[audio]',
        conversationExternalId: 'suggest:'.$conversation->id.':m1',
        attachments: audioKeyVoiceNote(),
        inputAudio: AiTranscription::options(null, audioKeyVoiceNote(), $tenant),
    );
}

test('a voice note on a non-OpenAI agent is transcribed on the workspace\'s own OpenAI key', function () {
    audioKeyFakeHub();
    [$tenant, $agent, $conversation] = audioKeyWorkspace('ANTHROPIC', [
        ['provider' => 'OPENAI', 'id' => 'own-openai'],
    ]);
    // Another workspace's OpenAI key, newer than ours: the one the hub picked.
    audioKeyWorkspace('OPENAI');

    audioKeySuggest($agent, $conversation, $tenant);

    $run = audioKeyRuns()[0];

    expect($run['inputAudio']['provider'])->toBe('OPENAI')
        ->and($run['inputAudio']['providerCredentialId'])->toBe('own-openai')
        ->and($run['message']['attachments'])->toHaveCount(1);
});

test('an OpenAI agent transcribes on its own key', function () {
    audioKeyFakeHub();
    [$tenant, $agent, $conversation] = audioKeyWorkspace('OPENAI', [
        ['provider' => 'OPENAI', 'id' => 'other-own-openai'],
    ]);

    audioKeySuggest($agent, $conversation, $tenant);

    expect(audioKeyRuns()[0]['inputAudio']['providerCredentialId'])->toBe('agent-key-'.$tenant->id);
});

test('a key named by the node that is not this workspace\'s is never forwarded', function () {
    audioKeyFakeHub();
    [$tenant, $agent] = audioKeyWorkspace('ANTHROPIC', [
        ['provider' => 'ELEVENLABS', 'id' => 'own-11labs'],
    ]);
    [, $otherAgent] = audioKeyWorkspace('ANTHROPIC', [
        ['provider' => 'ELEVENLABS', 'id' => 'their-11labs'],
    ]);

    $block = AiAudioCredential::scope(['provider' => 'ELEVENLABS', 'providerCredentialId' => 'their-11labs'], $agent);
    $theirs = AiAudioCredential::scope(['provider' => 'ELEVENLABS', 'providerCredentialId' => 'their-11labs'], $otherAgent);

    expect($block['providerCredentialId'])->toBe('own-11labs')
        ->and($theirs['providerCredentialId'])->toBe('their-11labs');
});

test('a workspace with no key for transcription sends the turn without the audio', function () {
    audioKeyFakeHub();
    [$tenant, $agent, $conversation] = audioKeyWorkspace('ANTHROPIC');
    audioKeyWorkspace('OPENAI');

    audioKeySuggest($agent, $conversation, $tenant);

    $run = audioKeyRuns()[0];

    expect($run)->not->toHaveKey('inputAudio')
        ->and($run['message'])->not->toHaveKey('attachments')
        ->and($run['metadata']['audioSkipped'])->toBe('no_workspace_key')
        ->and($run['message']['content'])->toBe('[audio]');
});

test('a reply nobody in the workspace can voice goes out in text', function () {
    audioKeyFakeHub();
    [, $agent, $conversation] = audioKeyWorkspace('ANTHROPIC');

    app(AiAgentHubTenantService::class)->runAgent(
        $agent,
        $conversation,
        'qual o preço?',
        responseAudio: ['enabled' => true, 'provider' => 'OPENAI', 'voice' => 'onyx', 'format' => 'opus'],
    );

    $run = audioKeyRuns()[0];

    expect($run)->not->toHaveKey('responseAudio')
        ->and($run['metadata']['voiceSkipped'])->toBe('no_workspace_key')
        ->and($run['metadata'])->not->toHaveKey('voiceRequested');
});

test('a rented key is used for audio only when the agent itself runs on one', function () {
    [, $ownKeyAgent] = audioKeyWorkspace('ANTHROPIC', [
        ['provider' => 'OPENAI', 'id' => 'rented-openai', 'rented' => true],
    ]);
    [, $rentedAgent] = audioKeyWorkspace('ANTHROPIC', [
        ['provider' => 'OPENAI', 'id' => 'rented-openai-2', 'rented' => true],
    ], agentRented: true);
    [, $mixedAgent] = audioKeyWorkspace('ANTHROPIC', [
        ['provider' => 'OPENAI', 'id' => 'own-openai-mixed'],
        ['provider' => 'OPENAI', 'id' => 'rented-openai-3', 'rented' => true],
    ], agentRented: true);

    // A workspace paying its own provider must not start spending ours.
    expect(AiAudioCredential::credentialFor($ownKeyAgent, 'OPENAI'))->toBeNull()
        ->and(AiAudioCredential::credentialFor($rentedAgent, 'OPENAI'))->toBe('rented-openai-2')
        ->and(AiAudioCredential::credentialFor($mixedAgent, 'OPENAI'))->toBe('own-openai-mixed');
});

test('a key still waiting for its real secret is passed over', function () {
    [, $agent] = audioKeyWorkspace('ANTHROPIC', [
        ['provider' => 'OPENAI', 'id' => 'working-openai'],
        ['provider' => 'OPENAI', 'id' => 'placeholder-openai', 'needs_key' => true],
    ]);

    expect(AiAudioCredential::credentialFor($agent, 'OPENAI'))->toBe('working-openai');
});

test('a call summary with no key to hear the call is refused, not invented', function () {
    audioKeyFakeHub();
    [$tenant, $agent, $conversation] = audioKeyWorkspace('ANTHROPIC');

    try {
        app(AiAgentHubTenantService::class)->runAgent(
            $agent,
            $conversation,
            'Summarize the call.',
            conversationExternalId: 'call-summary:1',
            attachments: audioKeyVoiceNote(),
            inputAudio: AiTranscription::options(null, audioKeyVoiceNote(), $tenant),
            retryWithoutExtras: false,
        );

        $this->fail('The run went out without anyone to hear the call.');
    } catch (UserFacingException $e) {
        expect($e->getErrorCode())->toBe('ai_audio_key_missing')
            ->and($e->getMessage())->toContain('OpenAI');
    }

    expect(audioKeyRuns())->toBe([]);
});

test('the vocabulary bench says which key is missing instead of testing nothing', function () {
    audioKeyFakeHub();
    [$tenant, $agent] = audioKeyWorkspace('ANTHROPIC');

    expect(fn () => app(AiAgentHubTenantService::class)->runAudioTest(
        $agent,
        'Transcribe this.',
        attachments: audioKeyVoiceNote(),
        inputAudio: AiTranscription::options(null, audioKeyVoiceNote(), $tenant),
    ))->toThrow(UserFacingException::class);

    expect(audioKeyRuns())->toBe([]);
});
