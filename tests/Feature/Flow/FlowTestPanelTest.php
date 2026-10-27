<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\AiHubAgent;
use App\Models\AiHubRun;
use App\Models\AiHubTenant;
use App\Models\Connection;
use App\Models\Flow;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/**
 * An author with a flow to test and an agent for its AI node.
 *
 * @return array{0: User, 1: Flow, 2: AiHubAgent, 3: Tenant}
 */
function flowTestPanel(string ...$permissions): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    foreach ($permissions ?: ['flows.view', 'flows.update'] as $name) {
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
        'hub_agent_id' => "hub-agent-test-{$tenant->id}",
        'external_id' => "agente_test_{$tenant->id}",
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ]);

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Vendas']);

    return [$user->fresh(), $flow, $agent, $tenant];
}

function flowTestHub(array $output): void
{
    Http::fake([
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_flow_test',
            'status' => 'COMPLETED',
            'output' => array_merge(['message' => 'Claro, posso ajudar com o pedido.', 'handoff' => false], $output),
        ]),
    ]);
}

test('the settings start from the first connection that runs the flow', function () {
    [$user, $flow, , $tenant] = flowTestPanel('flows.view');

    Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Telegram,
        'name' => 'Bot de vendas',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => ['token' => 'x'],
    ]);

    $this->actingAs($user)
        ->getJson("/api/flows/{$flow->id}/test/settings")
        ->assertOk()
        ->assertJsonPath('data.service_hours_open', true)
        ->assertJsonPath('data.connection.name', 'Bot de vendas')
        ->assertJsonPath('data.connection.channel', 'telegram')
        // Telegram draws no buttons, so the test starts with the numbered menu.
        ->assertJsonPath('data.connection.interactive', false);
});

test('a flow of another workspace cannot be tested', function () {
    [$user] = flowTestPanel();
    [, $otherFlow] = flowTestPanel();

    $this->actingAs($user)
        ->getJson("/api/flows/{$otherFlow->id}/test/settings")
        ->assertNotFound();
});

test('a greeting is answered by the welcome, a question by the AI', function () {
    [$user, $flow] = flowTestPanel();

    $this->actingAs($user)
        ->postJson("/api/flows/{$flow->id}/test/ai/opening", ['messages' => ['Oi! Bom dia, tudo bem?']])
        ->assertOk()
        ->assertJsonPath('data.needs_answer', false);

    $this->actingAs($user)
        ->postJson("/api/flows/{$flow->id}/test/ai/opening", ['messages' => ['oi', 'meu pedido não chegou']])
        ->assertOk()
        ->assertJsonPath('data.needs_answer', true);
});

test('an AI turn is a real run in its own hub conversation, with the flow\'s messages as context', function () {
    flowTestHub([]);
    [$user, $flow, $agent, $tenant] = flowTestPanel();

    $this->actingAs($user)
        ->postJson("/api/flows/{$flow->id}/test/ai/turn", [
            'ai_hub_agent_id' => $agent->id,
            'session' => 'sessao12345',
            'messages' => ['meu pedido', 'não chegou'],
            'transcript' => [
                ['speaker' => 'customer', 'text' => 'oi'],
                ['speaker' => 'automated', 'text' => 'O servidor de Cipayung está sem energia.'],
            ],
            'welcome' => 'Olá! Sou a assistente.',
            'contact_name' => 'Ana Teste',
        ])
        ->assertOk()
        ->assertJsonPath('data.reply', 'Claro, posso ajudar com o pedido.')
        ->assertJsonPath('data.handoff', false);

    $run = AiAgentFixtures::hubRuns()[0];

    expect($run['conversation']['externalId'])->toBe("flow-test-{$tenant->id}-{$flow->id}-sessao12345")
        ->and($run['conversation']['contactName'])->toBe('Ana Teste')
        ->and($run['message']['content'])->toContain("meu pedido\nnão chegou")
        ->and($run['message']['content'])->toContain('Automated message: O servidor de Cipayung está sem energia.')
        // Named, never quoted — the same rule as a real held welcome.
        ->and($run['message']['content'])->toContain('Do not greet the customer')
        ->and($run['message']['content'])->not->toContain('Sou a assistente')
        // A test never offers tools: they act on a stored conversation.
        ->and($run)->not->toHaveKey('tools');

    $row = AiHubRun::sole();

    expect($row->conversation_id)->toBeNull()
        ->and($row->tenant_id)->toBe($tenant->id)
        ->and($row->metadata)->toMatchArray(['purpose' => 'flow_test', 'flow_id' => $flow->id]);
});

test('a handoff signalled by the hub reaches the test', function () {
    flowTestHub(['handoff' => true, 'handoffDetails' => ['trigger' => 'humanRequested']]);
    [$user, $flow, $agent] = flowTestPanel();

    $this->actingAs($user)
        ->postJson("/api/flows/{$flow->id}/test/ai/turn", [
            'ai_hub_agent_id' => $agent->id,
            'session' => 'sessao12345',
            'messages' => ['quero falar com um atendente'],
        ])
        ->assertOk()
        ->assertJsonPath('data.handoff', true)
        ->assertJsonPath('data.handoff_reason', 'humanRequested');
});

test('an AI turn needs flows.update and an agent of this workspace', function () {
    flowTestHub([]);
    [$viewer, $flow, $agent] = flowTestPanel('flows.view');

    $this->actingAs($viewer)
        ->postJson("/api/flows/{$flow->id}/test/ai/turn", [
            'ai_hub_agent_id' => $agent->id,
            'session' => 'sessao12345',
            'messages' => ['oi'],
        ])
        ->assertForbidden();

    [$author, $authorFlow] = flowTestPanel();

    $this->actingAs($author)
        ->postJson("/api/flows/{$authorFlow->id}/test/ai/turn", [
            'ai_hub_agent_id' => $agent->id,
            'session' => 'sessao12345',
            'messages' => ['oi'],
        ])
        ->assertNotFound();

    expect(AiAgentFixtures::hubRuns())->toBe([]);
});

test('an HTTP call runs for real and its response becomes variables', function () {
    Http::fake([
        'api.loja.example.com/*' => Http::response(['pedido' => ['status' => 'enviado', 'itens' => [1, 2]]], 200),
    ]);
    [$user, $flow] = flowTestPanel();

    $this->actingAs($user)
        ->postJson("/api/flows/{$flow->id}/test/http", [
            'method' => 'POST',
            'url' => 'https://api.loja.example.com/pedidos/123',
            'headers' => [['key' => 'Authorization', 'value' => 'Bearer t0k3n']],
            'body' => '{"cpf":"12345678900"}',
            'timeout' => 10,
            'response_mappings' => [
                ['path' => 'pedido.status', 'variable' => 'status_pedido'],
                ['path' => 'pedido.itens', 'variable' => 'itens'],
                ['path' => 'http_status', 'variable' => 'codigo'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.branch', 'success')
        ->assertJsonPath('data.status', 200)
        ->assertJsonPath('data.mapped.status_pedido', 'enviado')
        ->assertJsonPath('data.mapped.itens', '[1,2]')
        ->assertJsonPath('data.mapped.codigo', '200');

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.loja.example.com/pedidos/123'
        && $request->hasHeader('Authorization', 'Bearer t0k3n')
        && $request->data() === ['cpf' => '12345678900']);
});

test('an HTTP call to a private address is refused before it is made', function () {
    Http::fake();
    [$user, $flow] = flowTestPanel();

    $this->actingAs($user)
        ->postJson("/api/flows/{$flow->id}/test/http", [
            'method' => 'GET',
            'url' => 'http://169.254.169.254/latest/meta-data',
        ])
        ->assertOk()
        ->assertJsonPath('data.branch', 'error')
        ->assertJsonPath('data.status', null)
        ->assertJsonPath('data.error', 'address_refused');

    Http::assertNothingSent();
});
