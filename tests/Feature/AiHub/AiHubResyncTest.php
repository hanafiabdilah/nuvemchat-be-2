<?php

use App\Models\AiHubAgent;
use App\Models\AiHubKnowledge;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubSkill;
use App\Models\AiHubTenant;
use App\Models\AiHubTrainingExample;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * A workspace whose agent, knowledge, skill and example all exist locally but
 * whose hub ids no longer resolve — the shape left behind when the hub was
 * rebuilt from nothing.
 */
function resyncScope(): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    $scope = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => null,
        'external_id' => 'Pingly_'.$tenant->id,
        'name' => 'Pingly_'.$tenant->id,
        'status' => 'ACTIVE',
    ]);

    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $scope->id,
        'hub_provider_credential_id' => 'cred-live',
        'provider' => 'OPENAI',
        'name' => 'Chave do cliente',
        'key_preview' => '••••1234',
        'status' => 'ACTIVE',
    ]);

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $scope->id,
        'ai_hub_provider_credential_id' => $credential->id,
        'hub_agent_id' => 'agent-that-the-hub-lost',
        'external_id' => 'atendimento',
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'system_prompt' => 'Você atende clientes da ProxyBR.',
        'status' => 'ACTIVE',
    ]);

    AiHubKnowledge::create([
        'ai_hub_agent_id' => $agent->id,
        'hub_knowledge_id' => 'knowledge-lost',
        'title' => 'Planos',
        'content' => 'O plano residencial custa R$ 90.',
    ]);

    AiHubSkill::create([
        'ai_hub_agent_id' => $agent->id,
        'hub_skill_id' => 'skill-lost',
        'name' => 'Consultar pedido',
        'instructions' => 'Peça o número do pedido.',
    ]);

    AiHubTrainingExample::create([
        'ai_hub_agent_id' => $agent->id,
        'hub_example_id' => 'example-lost',
        'type' => 'style_example',
        'input' => 'oi',
        'expected_output' => 'Olá! Como posso ajudar?',
    ]);

    return [$tenant, $scope, $credential, $agent];
}

it('re-pushes an agent the hub no longer has, keeping one local row', function () {
    [$tenant, , , $agent] = resyncScope();

    Http::fake([
        '*/provider-credentials' => Http::response([['id' => 'cred-live']], 200),
        '*/agents/*/knowledge' => Http::response(['id' => 'knowledge-new'], 201),
        '*/agents/*/skills' => Http::response(['id' => 'skill-new'], 201),
        '*/agents/*/training-examples' => Http::response(['id' => 'example-new'], 201),
        '*/agents/*/profile' => Http::response(['language' => 'pt-BR'], 200),
        // The hub has no agents at all — that is the whole premise.
        '*/agents' => function ($request) {
            return $request->method() === 'GET'
                ? Http::response([], 200)
                : Http::response(['id' => 'agent-new', 'externalId' => $request['externalId']], 201);
        },
    ]);

    $this->artisan('ai-hub:resync', ['--tenant' => $tenant->id])->assertSuccessful();

    // Repointed, not duplicated: the prompt and its training stay on one row.
    expect(AiHubAgent::count())->toBe(1);

    $agent->refresh();
    expect($agent->hub_agent_id)->toBe('agent-new')
        ->and($agent->system_prompt)->toBe('Você atende clientes da ProxyBR.')
        ->and($agent->knowledge()->first()->hub_knowledge_id)->toBe('knowledge-new')
        ->and($agent->skills()->first()->hub_skill_id)->toBe('skill-new')
        ->and($agent->trainingExamples()->first()->hub_example_id)->toBe('example-new');
});

it('finishes a run that died halfway instead of calling the agent done', function () {
    [$tenant, , , $agent] = resyncScope();

    // The state a failed first run leaves behind: the agent made it across,
    // and its skill, but the knowledge and the example did not.
    $agent->update(['hub_agent_id' => 'agent-new']);
    $agent->skills()->first()->update(['hub_skill_id' => 'skill-new']);

    $posted = [];

    Http::fake([
        '*/provider-credentials' => Http::response([['id' => 'cred-live']], 200),
        '*/agents/*/knowledge' => function ($request) use (&$posted) {
            if ($request->method() === 'GET') {
                return Http::response([], 200);
            }
            $posted[] = 'knowledge';

            return Http::response(['id' => 'knowledge-new'], 201);
        },
        '*/agents/*/skills' => function ($request) use (&$posted) {
            if ($request->method() === 'GET') {
                return Http::response([['id' => 'skill-new']], 200);
            }
            $posted[] = 'skill';

            return Http::response(['id' => 'skill-again'], 201);
        },
        '*/agents/*/training-examples' => function ($request) use (&$posted) {
            if ($request->method() === 'GET') {
                return Http::response([], 200);
            }
            $posted[] = 'example';

            return Http::response(['id' => 'example-new'], 201);
        },
        '*/agents/*/profile' => Http::response(['language' => 'pt-BR'], 200),
        '*/agents' => function ($request) use (&$posted) {
            if ($request->method() === 'GET') {
                return Http::response([['id' => 'agent-new']], 200);
            }
            $posted[] = 'agent';

            return Http::response(['id' => 'agent-duplicated'], 201);
        },
    ]);

    $this->artisan('ai-hub:resync', ['--tenant' => $tenant->id])->assertSuccessful();

    // Only what was actually missing — the agent is not created twice and the
    // skill the hub already holds is not duplicated.
    expect($posted)->toBe(['knowledge', 'example']);
    expect($agent->fresh()->hub_agent_id)->toBe('agent-new')
        ->and($agent->knowledge()->first()->hub_knowledge_id)->toBe('knowledge-new')
        ->and($agent->trainingExamples()->first()->hub_example_id)->toBe('example-new');
});

it('re-registers a missing credential with a placeholder and brings its agents back', function () {
    [$tenant, , $credential, $agent] = resyncScope();

    $posted = [];

    Http::fake([
        '*/provider-credentials' => function ($request) use (&$posted) {
            if ($request->method() === 'GET') {
                // Empty first, then holding whatever was just registered — the
                // hub as it looked after being rebuilt from nothing.
                return Http::response($posted ? [['id' => 'cred-new']] : [], 200);
            }
            $posted[] = $request['apiKey'];

            return Http::response(['id' => 'cred-new', 'keyPreview' => 'placeh...aB3x', 'status' => 'ACTIVE'], 201);
        },
        '*/agents/*/knowledge' => Http::response(['id' => 'knowledge-new'], 201),
        '*/agents/*/skills' => Http::response(['id' => 'skill-new'], 201),
        '*/agents/*/training-examples' => Http::response(['id' => 'example-new'], 201),
        '*/agents/*/profile' => Http::response(['language' => 'pt-BR'], 200),
        '*/agents' => fn ($request) => $request->method() === 'GET'
            ? Http::response([], 200)
            : Http::response(['id' => 'agent-new', 'externalId' => $request['externalId']], 201),
    ]);

    $this->artisan('ai-hub:resync', ['--tenant' => $tenant->id])->assertSuccessful();

    // A placeholder went out — long enough for the hub's 8-character floor, and
    // recognisable for whoever reads the key preview there.
    expect($posted)->toHaveCount(1)
        ->and($posted[0])->toStartWith('placeholder-key-')
        ->and(strlen($posted[0]))->toBeGreaterThan(8);

    $credential->refresh();
    expect($credential->hub_provider_credential_id)->toBe('cred-new')
        ->and($credential->metadata['needs_key'])->toBeTrue()
        // The old preview survives: it is the only remaining hint of which key
        // this row held, and the customer needs it to know which one to rotate.
        ->and($credential->key_preview)->toBe('••••1234');

    // And the agent came back with it, so the customer's only task is the key.
    expect($agent->fresh()->hub_agent_id)->toBe('agent-new');
});

it('clears the placeholder marking once a real key is entered', function () {
    [, , $credential] = resyncScope();

    $credential->update(['metadata' => ['needs_key' => true]]);

    Http::fake([
        // The hub echoes back the metadata it was given at creation — including
        // the placeholder marking. Clearing the flag before this lands would be
        // silently undone, leaving the badge up on a working credential.
        '*/provider-credentials/*' => Http::response([
            'id' => 'cred-live',
            'keyPreview' => 'sk-abc...9xyz',
            'status' => 'ACTIVE',
            'metadata' => ['needs_key' => true],
        ], 200),
    ]);

    app(AiAgentHubTenantService::class)
        ->updateProviderCredential($credential, ['apiKey' => 'sk-'.str_repeat('a', 40)]);

    expect($credential->fresh()->metadata['needs_key'])->toBeFalse();
});

it('rebuilds an agent the hub lost instead of failing the edit', function () {
    [, , , $agent] = resyncScope();

    $patched = false;

    Http::fake([
        // The credential is still there; only the agent went missing.
        '*/provider-credentials' => Http::response([['id' => 'cred-live']], 200),
        '*/agents/*' => function ($request) use (&$patched) {
            if ($patched) {
                return Http::response(['id' => 'agent-new', 'name' => 'Atendimento'], 200);
            }
            $patched = true;

            return Http::response(['message' => 'Agent not found.'], 404);
        },
        '*/agents' => Http::response(['id' => 'agent-new', 'externalId' => 'Pingly_1_atendimento'], 201),
    ]);

    // The person editing knows a name and a prompt, not an id — so this has to
    // succeed rather than report an object they cannot see.
    app(AiAgentHubTenantService::class)->updateAgent($agent, ['name' => 'Atendimento']);

    expect($agent->fresh()->hub_agent_id)->toBe('agent-new');
});

it('writes nothing on a dry run', function () {
    [$tenant, , , $agent] = resyncScope();

    Http::fake([
        '*/provider-credentials' => Http::response([['id' => 'cred-live']], 200),
        '*/agents' => Http::response([], 200),
    ]);

    $this->artisan('ai-hub:resync', ['--tenant' => $tenant->id, '--dry-run' => true])
        ->assertSuccessful();

    expect($agent->fresh()->hub_agent_id)->toBe('agent-that-the-hub-lost');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('still clears a local row when the hub says it never had it', function () {
    [, , $credential, $agent] = resyncScope();

    // Without this, a stale row can be neither updated nor deleted, and the
    // workspace has no way to replace it.
    Http::fake([
        '*' => Http::response(['message' => 'Not found', 'statusCode' => 404], 404),
    ]);

    $service = app(AiAgentHubTenantService::class);

    $service->deleteAgent($agent);
    $service->deleteProviderCredential($credential);

    expect(AiHubAgent::count())->toBe(0)
        ->and(AiHubProviderCredential::count())->toBe(0);
});
