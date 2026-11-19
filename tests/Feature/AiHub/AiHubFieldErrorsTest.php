<?php

use App\Models\AiHubProviderCredential;
use App\Services\AiAgentHub\AiHubFieldErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreditFixtures;

/**
 * Saving an agent or a key: the hub's refusal lands under the field it is about.
 *
 * Every body faked here is one the live hub actually answered when probed with
 * invalid payloads (Nov 2026) — not a shape we expect it to send. The rules
 * themselves (a 10-character prompt, an 8-character key) are the hub's and are
 * not repeated in this codebase: nothing is refused here before the hub says so.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function fieldErrorsWorkspace(string $locale = 'pt_BR'): array
{
    [$tenant, $user, $hubTenant] = CreditFixtures::workspace();
    $user->forceFill(['locale' => $locale])->save();

    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_provider_credential_id' => 'hub-cred-own',
        'provider' => 'OPENAI',
        'name' => 'Minha chave',
        'status' => 'ACTIVE',
    ]);

    return [$tenant, $user, $hubTenant, $credential];
}

/** The hub's 400, exactly as it answers it. */
function hubBadRequest(array $messages): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['message' => $messages, 'error' => 'Bad Request', 'statusCode' => 400], 400);
}

it('reads the hub\'s class-validator messages as structure', function () {
    expect(AiHubFieldErrors::parse('systemPrompt must be longer than or equal to 10 characters'))
        ->toBe(['property' => 'systemPrompt', 'rule' => 'min_length', 'value' => '10'])
        ->and(AiHubFieldErrors::parse('apiKey must be longer than or equal to 8 characters'))
        ->toBe(['property' => 'apiKey', 'rule' => 'min_length', 'value' => '8'])
        ->and(AiHubFieldErrors::parse('temperature must not be greater than 2'))
        ->toBe(['property' => 'temperature', 'rule' => 'max_value', 'value' => '2'])
        ->and(AiHubFieldErrors::parse('maxTokens must not be less than 1'))
        ->toBe(['property' => 'maxTokens', 'rule' => 'min_value', 'value' => '1'])
        ->and(AiHubFieldErrors::parse('temperature must be a number conforming to the specified constraints'))
        ->toBe(['property' => 'temperature', 'rule' => 'invalid', 'value' => null])
        // A field we sent that the hub does not know is our bug, not their data.
        ->and(AiHubFieldErrors::parse('property foo should not exist'))->toBeNull();
});

it('lets the hub decide a short prompt, then says it under the prompt', function () {
    [, $user, , $credential] = fieldErrorsWorkspace();

    $sent = false;
    Http::fake([
        '*/agents' => function () use (&$sent) {
            $sent = true;

            return hubBadRequest([
                'systemPrompt must be longer than or equal to 10 characters',
                'systemPrompt must be a string',
            ]);
        },
    ]);

    $response = $this->actingAs($user)->postJson('/api/ai-hub/agents', [
        'providerCredentialId' => $credential->id,
        'name' => 'Suporte',
        'model' => 'gpt-4o-mini',
        'systemPrompt' => 'Oi',
    ])->assertStatus(422);

    // The request went out: the length rule is the hub's, not ours.
    expect($sent)->toBeTrue()
        // The first rule the hub listed for the field, in our words — the
        // length, not "must be a string".
        ->and($response->json('errors.systemPrompt'))
        ->toBe(['O campo Prompt do Sistema precisa ter pelo menos 10 caracteres.'])
        ->and($response->json('message'))->not->toContain('longer than');
});

it('puts each refused field under its own name', function () {
    [, $user, $agentTenant] = fieldErrorsWorkspace();
    $agent = CreditFixtures::agent($agentTenant);

    Http::fake([
        '*/agents/*' => hubBadRequest([
            'name must be longer than or equal to 2 characters',
            'temperature must not be greater than 2',
            'maxTokens must not be less than 1',
        ]),
    ]);

    $this->actingAs($user)->patchJson("/api/ai-hub/agents/{$agent->id}", [
        'name' => 'A',
        'temperature' => 1.9,
        'maxTokens' => 1,
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', 'O campo Nome precisa ter pelo menos 2 caracteres.')
        ->assertJsonPath('errors.temperature.0', 'O campo Temperatura pode ser no máximo 2.')
        ->assertJsonPath('errors.maxTokens.0', 'O campo Máximo de Tokens precisa ser no mínimo 1.');
});

it('speaks the person\'s language', function () {
    [, $user, , $credential] = fieldErrorsWorkspace('en');

    Http::fake(['*/agents' => hubBadRequest(['systemPrompt must be longer than or equal to 10 characters'])]);

    $this->actingAs($user)->postJson('/api/ai-hub/agents', [
        'providerCredentialId' => $credential->id,
        'name' => 'Support',
        'model' => 'gpt-4o-mini',
        'systemPrompt' => 'Hi',
    ])->assertJsonPath('errors.systemPrompt.0', 'System Prompt must be at least 10 characters.');
});

it('names the longest text when the hub says the request is too large', function () {
    [, $user, , $credential] = fieldErrorsWorkspace();

    // The hub's own answer to a body over its limit (a ~120k-character prompt).
    Http::fake(['*/agents' => Http::response(['statusCode' => 413, 'message' => 'request entity too large'], 413)]);

    $this->actingAs($user)->postJson('/api/ai-hub/agents', [
        'providerCredentialId' => $credential->id,
        'name' => 'Suporte',
        'model' => 'gpt-4o-mini',
        'description' => 'Atendimento',
        'systemPrompt' => str_repeat('Instrução longa. ', 2000),
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.systemPrompt.0', 'O campo Prompt do Sistema ficou grande demais para ser salvo. Encurte o texto e tente novamente.');
});

it('keeps the generic copy for what the customer never typed', function () {
    [, $user, , $credential] = fieldErrorsWorkspace();

    Http::fake([
        '*/agents' => hubBadRequest([
            'property foo should not exist',
            'externalId must be longer than or equal to 2 characters',
        ]),
    ]);

    $this->actingAs($user)->postJson('/api/ai-hub/agents', [
        'providerCredentialId' => $credential->id,
        'name' => 'Suporte',
        'model' => 'gpt-4o-mini',
        'systemPrompt' => 'Você é um atendente educado.',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ai_invalid_configuration')
        ->assertJsonMissingPath('errors');
});

it('lets the hub decide a short API key, then says it under the key', function () {
    [, $user] = fieldErrorsWorkspace();

    Http::fake([
        '*/provider-credentials' => hubBadRequest(['apiKey must be longer than or equal to 8 characters']),
    ]);

    $this->actingAs($user)->postJson('/api/ai-hub/provider-credentials', [
        'provider' => 'OPENAI',
        'name' => 'Produção',
        'apiKey' => 'sk-123',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.apiKey.0', 'O campo Chave de API precisa ter pelo menos 8 caracteres.');

    Http::assertSentCount(1);
});

it('uses the flow assistant\'s own field names', function () {
    [$tenant, $user] = fieldErrorsWorkspace();

    $errors = AiHubFieldErrors::fromException(
        \App\Support\Errors\UpstreamError::exception(
            \App\Support\Errors\UpstreamProvider::AiHub,
            'apiKey must be longer than or equal to 8 characters',
            status: 400,
            details: ['status' => 400, 'hub_messages' => ['apiKey must be longer than or equal to 8 characters']],
        ),
        AiHubFieldErrors::CREDENTIAL,
        ['apiKey' => 'api_key', 'defaultModel' => 'model'],
    );

    expect($errors)->toHaveKey('api_key');
});

it('refuses to change a key\'s status, which the hub has no way to do', function () {
    [, $user, , $credential] = fieldErrorsWorkspace();

    // The hub answers `property status should not exist` and has no route that
    // changes it (probed Nov 2026) — sending it on would only buy the generic
    // "not accepted". Its one way to switch a key off is DELETE.
    $this->actingAs($user)
        ->patchJson("/api/ai-hub/provider-credentials/{$credential->id}", ['status' => 'DISABLED'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'O status de uma chave não pode ser alterado. Para deixar de usá-la, exclua a chave.');

    Http::assertNothingSent();
});

it('still edits a key without a status in the request', function () {
    [, $user, , $credential] = fieldErrorsWorkspace();

    $sent = null;
    Http::fake([
        '*/provider-credentials/*' => function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response(['id' => 'hub-cred-own', 'name' => $sent['name'], 'status' => 'ACTIVE'], 200);
        },
    ]);

    $this->actingAs($user)
        ->patchJson("/api/ai-hub/provider-credentials/{$credential->id}", ['name' => 'Chave de produção'])
        ->assertOk();

    expect($sent)->toBe(['name' => 'Chave de produção'])
        ->and($credential->fresh()->name)->toBe('Chave de produção');
});
