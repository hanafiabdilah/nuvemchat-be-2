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

it('leaves the agent alone while its provider credential is still missing', function () {
    [$tenant, , , $agent] = resyncScope();

    Http::fake([
        // The key was never stored here, so the credential cannot come back on
        // its own — and an agent created against a missing one is a 400.
        '*/provider-credentials' => Http::response([], 200),
        '*/agents' => Http::response([], 200),
    ]);

    $this->artisan('ai-hub:resync', ['--tenant' => $tenant->id])
        ->expectsOutputToContain('waiting on its provider credential')
        ->assertSuccessful();

    expect($agent->fresh()->hub_agent_id)->toBe('agent-that-the-hub-lost');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('reports credentials whose keys have to be entered again', function () {
    [$tenant] = resyncScope();

    Http::fake([
        '*/provider-credentials' => Http::response([], 200),
        '*/agents' => Http::response([], 200),
    ]);

    $this->artisan('ai-hub:resync', ['--tenant' => $tenant->id])
        ->expectsOutputToContain('Chave do cliente')
        ->assertSuccessful();
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
