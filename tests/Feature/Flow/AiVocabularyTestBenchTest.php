<?php

use App\Models\AiHubAgent;
use App\Models\AiHubRun;
use App\Models\AiHubTenant;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/**
 * Somebody who may edit the vocabulary, with an agent to run the test on.
 *
 * @return array{0: User, 1: AiHubAgent, 2: Tenant}
 */
function vocabularyBench(string ...$permissions): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    foreach ($permissions ?: ['ai-agents.view', 'ai-agents.update'] as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    $hubTenant = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => null,
        'external_id' => "Pingly_{$tenant->id}",
        'name' => "Pingly_{$tenant->id}",
        'status' => 'ACTIVE',
    ]);

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => "hub-agent-bench-{$tenant->id}",
        'external_id' => "agente_bench_{$tenant->id}",
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ]);

    return [$user->fresh(), $agent, $tenant];
}

/** The hub answers every run with $output; the generated voice file is an MP3. */
function vocabularyBenchHub(array $output, string $status = 'COMPLETED'): void
{
    Http::fake([
        'api-ia.ipbr.pro/v1/media/*' => Http::response('ID3-fake-mp3', 200, ['Content-Type' => 'audio/mpeg']),
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_bench',
            'status' => $status,
            'output' => $status === 'COMPLETED' ? array_merge(['message' => 'ok', 'handoff' => false], $output) : null,
            'error' => $status === 'COMPLETED' ? null : $output,
        ]),
    ]);
}

function benchRecording(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('gravacao.ogg', AiAgentFixtures::opusBytes());
}

test('a recording is transcribed with the list on screen, not the saved one', function () {
    Storage::fake('local', ['serve' => true]);
    vocabularyBenchHub([
        'inputAudio' => ['items' => [[
            'text' => 'Meu proxy socks five não conecta no ipv6',
            'model' => 'gpt-4o-mini-transcribe',
        ]]],
    ]);

    [$user, $agent, $tenant] = vocabularyBench();
    $tenant->forceFill(['audio_dictionary' => [['term' => 'ProxyBR', 'aliases' => []]]])->save();

    $this->actingAs($user)
        ->post('/api/ai-hub/vocabulary/test/listen', [
            'ai_hub_agent_id' => $agent->id,
            'terms' => json_encode([
                ['term' => 'SOCKS5', 'aliases' => ['socks five']],
                ['term' => 'IPv6'],
            ]),
            'audio' => benchRecording(),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.transcript', 'Meu proxy socks five não conecta no ipv6')
        ->assertJsonPath('data.provider', 'openai')
        // The one distinction worth making: the list is working, or the
        // model still wrote one of the other spellings.
        ->assertJsonPath('data.matches', [
            ['term' => 'SOCKS5', 'heard_as' => 'socks five', 'status' => 'alias'],
            ['term' => 'IPv6', 'heard_as' => 'IPv6', 'status' => 'term'],
        ]);

    $run = AiAgentFixtures::hubRuns()[0];

    expect($run['message']['attachments'][0]['type'])->toBe('audio')
        ->and($run['message']['attachments'][0]['mimeType'])->toBe('audio/ogg')
        ->and($run['message']['attachments'][0]['url'])->toContain('vocabulary-tests/')
        ->and($run['inputAudio']['prompt'])->toContain('SOCKS5, IPv6')
        ->and($run['inputAudio']['prompt'])->not->toContain('ProxyBR');

    // Tested, not saved — and the recording is not kept.
    expect($tenant->fresh()->audio_dictionary)->toBe([['term' => 'ProxyBR', 'aliases' => []]])
        ->and(Storage::disk('local')->allFiles('vocabulary-tests'))->toBe([]);

    // A real run: counted and billable like any other, just without a thread.
    $row = AiHubRun::sole();

    expect($row->conversation_id)->toBeNull()
        ->and($row->tenant_id)->toBe($tenant->id)
        ->and($row->metadata['purpose'])->toBe('vocabulary_test');
});

test('an emptied list is tested as empty rather than falling back to the saved one', function () {
    Storage::fake('local', ['serve' => true]);
    config(['ai.audio.prompt' => null]);
    vocabularyBenchHub(['inputAudio' => ['items' => [['text' => 'olá']]]]);

    [$user, $agent, $tenant] = vocabularyBench();
    $tenant->forceFill(['audio_dictionary' => [['term' => 'ProxyBR', 'aliases' => []]]])->save();

    $this->actingAs($user)
        ->post('/api/ai-hub/vocabulary/test/listen', [
            'ai_hub_agent_id' => $agent->id,
            'terms' => '[]',
            'audio' => benchRecording(),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect(AiAgentFixtures::hubRuns()[0]['inputAudio'])->not->toHaveKey('prompt');
});

test('a recording with nothing recognised says so instead of showing an empty result', function () {
    Storage::fake('local', ['serve' => true]);
    vocabularyBenchHub(['inputAudio' => ['items' => []]]);

    [$user, $agent] = vocabularyBench();

    $this->actingAs($user)
        ->post('/api/ai-hub/vocabulary/test/listen', [
            'ai_hub_agent_id' => $agent->id,
            'audio' => benchRecording(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'transcript_empty');
});

test('a sentence is voiced with the pronunciations on screen and comes back playable', function () {
    // Whatever a channel would force, the bench asks for something a browser plays.
    config(['ai.voice.format' => 'opus']);

    vocabularyBenchHub([
        'message' => 'O SOCKS5 funciona com IPv6.',
        'audio' => [
            'status' => 'generated',
            'url' => 'https://api-ia.ipbr.pro/v1/media/audio/abc',
            'mimeType' => 'audio/mpeg',
            'format' => 'mp3',
        ],
    ]);

    [$user, $agent] = vocabularyBench();

    $this->actingAs($user)
        ->postJson('/api/ai-hub/vocabulary/test/speak', [
            'ai_hub_agent_id' => $agent->id,
            'text' => 'O SOCKS5 funciona com IPv6.',
            'terms' => [
                ['term' => 'IPv6', 'aliases' => [], 'speak_as' => 'ipê vê seis'],
                ['term' => 'CNPJ', 'aliases' => [], 'speak_as' => 'cê ene pê jota'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.audio', 'data:audio/mpeg;base64,' . base64_encode('ID3-fake-mp3'))
        ->assertJsonPath('data.text', 'O SOCKS5 funciona com IPv6.')
        ->assertJsonPath('data.provider', 'openai')
        // Only what the sentence actually gave the voice a reason to use.
        ->assertJsonPath('data.pronunciations', [['term' => 'IPv6', 'speak_as' => 'ipê vê seis']]);

    $run = AiAgentFixtures::hubRuns()[0];

    expect($run['message']['content'])->toContain('O SOCKS5 funciona com IPv6.')
        ->and($run['responseAudio']['format'])->toBe('mp3')
        ->and($run['responseAudio']['pronunciationReplacements'])->toBe([
            ['term' => 'IPv6', 'speakAs' => 'ipê vê seis'],
            ['term' => 'CNPJ', 'speakAs' => 'cê ene pê jota'],
        ]);
});

test('ElevenLabs without a voice says it spoke with the default instead', function () {
    config(['ai.voice.elevenlabs_voice_id' => null]);

    vocabularyBenchHub([
        'message' => 'Olá',
        'audio' => ['status' => 'generated', 'url' => 'https://api-ia.ipbr.pro/v1/media/audio/abc', 'mimeType' => 'audio/mpeg'],
    ]);

    [$user, $agent] = vocabularyBench();

    $this->actingAs($user)
        ->postJson('/api/ai-hub/vocabulary/test/speak', [
            'ai_hub_agent_id' => $agent->id,
            'text' => 'Olá',
            'provider' => 'elevenlabs',
        ])
        ->assertOk()
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonPath('data.fell_back_to_default', true);
});

test('a voice that was not generated is reported in our words, not the provider\'s', function () {
    vocabularyBenchHub([
        'message' => 'Olá',
        'audio' => ['status' => 'failed', 'error' => ['message' => 'ElevenLabs speech failed: 401 missing_permissions']],
    ]);

    [$user, $agent] = vocabularyBench();

    $this->actingAs($user)
        ->postJson('/api/ai-hub/vocabulary/test/speak', [
            'ai_hub_agent_id' => $agent->id,
            'text' => 'Olá',
        ])
        ->assertStatus(502)
        ->assertJsonPath('code', 'ai_audio_unavailable')
        ->assertDontSee('missing_permissions');
});

test('a failed run is reported, not retried without the audio', function () {
    Storage::fake('local', ['serve' => true]);
    vocabularyBenchHub(['message' => 'ElevenLabs transcription failed: 401 missing_permissions'], status: 'FAILED');

    [$user, $agent] = vocabularyBench();

    $this->actingAs($user)
        ->post('/api/ai-hub/vocabulary/test/listen', [
            'ai_hub_agent_id' => $agent->id,
            'audio' => benchRecording(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'ai_audio_unavailable');

    // A text-only retry would answer "ok" and report success on the one
    // thing that just failed.
    expect(AiAgentFixtures::hubRuns())->toHaveCount(1)
        ->and(AiHubRun::count())->toBe(0);
});

test('an agent from another workspace cannot be used', function () {
    vocabularyBenchHub(['message' => 'ok']);

    [$user] = vocabularyBench();
    [, $foreignAgent] = vocabularyBench();

    $this->actingAs($user)
        ->postJson('/api/ai-hub/vocabulary/test/speak', [
            'ai_hub_agent_id' => $foreignAgent->id,
            'text' => 'Olá',
        ])
        ->assertNotFound();

    Http::assertNothingSent();
});

test('only someone who may edit the vocabulary can spend runs testing it', function () {
    vocabularyBenchHub(['message' => 'ok']);

    [$user, $agent] = vocabularyBench('ai-agents.view');

    $this->actingAs($user)
        ->postJson('/api/ai-hub/vocabulary/test/speak', [
            'ai_hub_agent_id' => $agent->id,
            'text' => 'Olá',
        ])
        ->assertForbidden();
});
