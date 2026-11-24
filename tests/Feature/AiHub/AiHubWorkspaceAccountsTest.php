<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\TrainedAgent\HireSource;
use App\Enums\TrainedAgent\HireStatus;
use App\Exceptions\UpstreamServiceException;
use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubTenant;
use App\Models\AiMediaGeneration;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FlowAssistantSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TrainedAgentBlueprint;
use App\Models\TrainedAgentCategory;
use App\Models\TrainedAgentHire;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubService;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiAgentHub\AiHubAccounts;
use App\Services\AiAgentHub\AiMediaGenerator;
use App\Services\AiTokens\AiTokenRentalService;
use App\Services\Flow\FlowExecutor;
use App\Services\FlowAssistant\FlowAssistantService;
use App\Services\TrainedAgent\TrainedAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiAgentFixtures;
use Tests\Support\CreditFixtures;

uses(RefreshDatabase::class);

/*
 * One AI hub account per workspace: every call made for a workspace names its
 * account in `X-Hub-Tenant`, and nothing changes while the flag is off.
 *
 * Helper names carry the file's prefix: Pest loads every test file into one
 * process, so a name shared with a sibling file is a fatal redeclare.
 */

function hubAccountsOn(bool $on = true): void
{
    Setting::set(AiAgentHubConfig::KEY_PER_WORKSPACE_ACCOUNTS, $on ? '1' : '0');
}

/**
 * A workspace with a provider key and an agent. `$account` set = it already
 * has a hub account (recorded the way AiHubAccounts records one).
 *
 * @return array{0: Tenant, 1: AiHubTenant, 2: AiHubProviderCredential, 3: AiHubAgent}
 */
function hubAccountWorkspace(?string $account = null, bool $withData = true): array
{
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    $user = User::factory()->create(['email' => 'acct-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id, 'billing_name' => 'Loja '.uniqid()]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $scope = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => $account,
        'external_id' => 'Pingly_'.$tenant->id,
        'name' => 'Pingly_'.$tenant->id,
        'status' => 'ACTIVE',
        'metadata' => $account ? ['hub_account' => ['mode' => 'shared', 'external_ref' => 'pingly-tenant-'.$tenant->id]] : null,
    ]);

    if (! $withData) {
        return [$tenant->fresh(), $scope, null, null];
    }

    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $scope->id,
        'hub_provider_credential_id' => 'cred-'.uniqid(),
        'provider' => 'OPENAI',
        'name' => 'Chave do cliente',
        'status' => 'ACTIVE',
    ]);

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $scope->id,
        'ai_hub_provider_credential_id' => $credential->id,
        'hub_agent_id' => 'agent-'.uniqid(),
        'external_id' => 'Pingly_'.$scope->id.'_atendimento',
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'system_prompt' => 'Você atende clientes.',
        'status' => 'ACTIVE',
    ]);

    return [$tenant->fresh(), $scope, $credential, $agent];
}

function hubAccountConversation(Tenant $tenant): Conversation
{
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

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
    ]);
}

/**
 * The hub (and WhatsApp, for the queued AI turn). `$platform` answers
 * `POST /platform/tenants`; by default it opens the account it was asked for.
 */
function hubAccountFake(?Closure $platform = null, ?Closure $agentPatch = null): void
{
    $platform ??= fn (Request $request) => Http::response([
        'id' => 'acct-'.$request['externalRef'],
        'externalRef' => $request['externalRef'],
        'name' => $request['name'],
        'isolation' => strtoupper((string) $request['mode']),
        'createdAt' => now()->toIso8601String(),
    ], 201);

    Http::fake([
        'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]]),
        'api-ia.ipbr.pro/*' => function (Request $request) use ($platform, $agentPatch) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            if (str_ends_with($path, '/platform/tenants')) {
                return $platform($request);
            }

            if (str_ends_with($path, '/runs')) {
                return Http::response([
                    'id' => 'run_'.uniqid(),
                    'status' => 'COMPLETED',
                    'output' => ['message' => 'Olá! Como posso ajudar?', 'handoff' => false],
                ]);
            }

            if (str_contains($path, '/media-generations')) {
                return Http::response(['id' => 'gen_'.uniqid(), 'status' => 'RUNNING']);
            }

            if (str_ends_with($path, '/provider-credentials')) {
                return $method === 'POST'
                    ? Http::response([
                        'id' => 'cred-'.uniqid(),
                        'provider' => $request['provider'] ?? 'OPENAI',
                        'name' => $request['name'] ?? 'Chave',
                        'keyPreview' => '••••0000',
                        'status' => 'ACTIVE',
                    ], 201)
                    : Http::response([]);
            }

            if (preg_match('#/agents/[^/]+/(knowledge|skills|training-examples)$#', $path)) {
                return Http::response(['id' => 'child-'.uniqid()], 201);
            }

            if (preg_match('#/agents/[^/]+/profile$#', $path)) {
                return Http::response(['language' => 'pt-BR']);
            }

            if ($agentPatch && $method === 'PATCH' && preg_match('#/agents/[^/]+$#', $path)) {
                return $agentPatch($request);
            }

            if (preg_match('#/agents(/[^/]+)?$#', $path)) {
                return $method === 'GET' && str_ends_with($path, '/agents')
                    ? Http::response([])
                    : Http::response([
                        'id' => 'agent-'.uniqid(),
                        'externalId' => $request['externalId'] ?? 'Pingly_agent',
                        'name' => $request['name'] ?? 'Agente',
                        'status' => 'ACTIVE',
                    ], $method === 'POST' ? 201 : 200);
            }

            return Http::response([]);
        },
    ]);
}

/**
 * Every hub request recorded since `$from`, as [method, path, X-Hub-Tenant].
 *
 * @return list<array{0: string, 1: string, 2: ?string}>
 */
function hubAccountRequests(int $from = 0): array
{
    $rows = [];

    foreach (Http::recorded()->slice($from) as [$request]) {
        if (parse_url($request->url(), PHP_URL_HOST) !== 'api-ia.ipbr.pro') {
            continue;
        }

        $rows[] = [
            $request->method(),
            (string) parse_url($request->url(), PHP_URL_PATH),
            $request->header(AiHubAccounts::HEADER)[0] ?? null,
        ];
    }

    return $rows;
}

function hubAccountPlatformCalls(): array
{
    return array_values(array_filter(
        hubAccountRequests(),
        fn (array $row) => str_ends_with($row[1], '/platform/tenants'),
    ));
}

test('flag off: no account header anywhere and no platform call', function () {
    hubAccountFake();
    [$tenant, $scope, , $agent] = hubAccountWorkspace(account: 'acct-A');
    $hub = app(AiAgentHubTenantService::class);

    app(AiAgentHubService::class)->createTenant($tenant);
    $hub->createProviderCredential($scope, ['provider' => 'OPENAI', 'name' => 'Outra', 'apiKey' => 'sk-test-12345678']);
    $hub->updateAgent($agent, ['name' => 'Atendimento 2']);
    $hub->createAgentKnowledge($agent, ['title' => 'Planos', 'content' => 'R$ 90']);
    $hub->runFlowTest($agent, 'flow-test-1', 'oi', 1, 'Ana');

    $requests = hubAccountRequests();

    expect($requests)->not->toBeEmpty()
        ->and(array_filter($requests, fn ($row) => $row[2] !== null))->toBe([])
        ->and(hubAccountPlatformCalls())->toBe([])
        ->and($hub->ensureHubAccount($scope))->toBeNull();
});

test('a workspace with no AI data gets an isolated account, opened once', function () {
    hubAccountsOn();
    hubAccountFake();
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    $user = User::factory()->create(['name' => 'Ana Souza']);
    $tenant = Tenant::create(['user_id' => $user->id, 'billing_name' => 'Loja Aurora']);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $scope = app(AiAgentHubService::class)->createTenant($tenant);
    app(AiAgentHubService::class)->createTenant($tenant);

    $calls = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/platform/tenants'));

    expect($calls)->toHaveCount(1);

    $request = $calls->first()[0];

    expect($request->data())->toBe([
        'externalRef' => 'pingly-tenant-'.$tenant->id,
        'name' => 'Loja Aurora',
        'mode' => 'isolated',
    ])
        ->and($request->header('Authorization')[0])->toBe('Bearer platform-hub-token')
        ->and($request->hasHeader(AiHubAccounts::HEADER))->toBeFalse()
        ->and($scope->fresh()->hub_tenant_id)->toBe('acct-pingly-tenant-'.$tenant->id)
        ->and(AiHubAccounts::recorded($scope->fresh())['mode'])->toBe('isolated');
});

test('a workspace that already has AI data gets a shared account', function () {
    hubAccountsOn();
    hubAccountFake();
    [, $scope] = hubAccountWorkspace();

    $id = app(AiAgentHubTenantService::class)->ensureHubAccount($scope);

    expect($id)->toBe('acct-pingly-tenant-'.$scope->tenant_id)
        ->and(hubAccountPlatformCalls()[0])->not->toBeNull()
        ->and(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/platform/tenants'))->first()[0]['mode'])->toBe('shared')
        ->and(AiHubAccounts::recorded($scope->fresh())['mode'])->toBe('shared');
});

test('a flow assistant setting alone makes the account shared', function () {
    hubAccountsOn();
    hubAccountFake();
    [$tenant, $scope] = hubAccountWorkspace(withData: false);
    FlowAssistantSetting::create(['tenant_id' => $tenant->id, 'model' => 'gpt-4o-mini']);

    expect(app(AiHubAccounts::class)->plannedMode($scope))->toBe('shared');
});

test('a request that lost the race uses the account the other one opened', function () {
    hubAccountsOn();
    hubAccountFake();
    [, $scope] = hubAccountWorkspace();

    // The other request opened it while this one held a stale copy.
    AiHubTenant::whereKey($scope->id)->update([
        'hub_tenant_id' => 'acct-opened-meanwhile',
        'metadata' => json_encode(['hub_account' => ['mode' => 'shared']]),
    ]);

    expect($scope->hub_tenant_id)->toBeNull()
        ->and(app(AiHubAccounts::class)->ensure($scope))->toBe('acct-opened-meanwhile')
        ->and(hubAccountPlatformCalls())->toBe([]);
});

test('a hub that cannot open the account does not block the workspace, and the command opens it later', function () {
    hubAccountsOn();
    $down = true;
    hubAccountFake(platform: function (Request $request) use (&$down) {
        return $down
            ? Http::response(['message' => 'unavailable'], 503)
            : Http::response(['id' => 'acct-late', 'externalRef' => $request['externalRef'], 'isolation' => 'SHARED'], 201);
    });
    [, $scope] = hubAccountWorkspace();
    $hub = app(AiAgentHubTenantService::class);

    $hub->listAgents($scope);
    $hub->listAgents($scope->fresh());

    $requests = hubAccountRequests();
    $listCalls = array_values(array_filter($requests, fn ($row) => str_ends_with($row[1], '/agents')));

    // One attempt, then the back-off: the second call did not knock again.
    expect(hubAccountPlatformCalls())->toHaveCount(1)
        ->and($listCalls)->toHaveCount(2)
        ->and($listCalls[0][2])->toBeNull()
        ->and($listCalls[1][2])->toBeNull()
        ->and($scope->fresh()->hub_tenant_id)->toBeNull();

    $down = false;
    $this->artisan('ai-hub:provision-accounts')->assertSuccessful();

    expect($scope->fresh()->hub_tenant_id)->toBe('acct-late');

    $before = Http::recorded()->count();
    $hub->listAgents($scope->fresh());

    expect(hubAccountRequests($before)[0][2])->toBe('acct-late');
});

test('an isolated account is not used for a workspace whose data is in the shared one', function () {
    hubAccountsOn();
    // Creation is idempotent by externalRef and never changes the mode: an
    // account opened earlier as isolated comes back isolated.
    hubAccountFake(platform: fn (Request $request) => Http::response([
        'id' => 'acct-isolated-too-early',
        'externalRef' => $request['externalRef'],
        'isolation' => 'ISOLATED',
    ]));
    [, $scope] = hubAccountWorkspace();

    expect(app(AiHubAccounts::class)->ensure($scope, force: true))->toBeNull()
        ->and($scope->fresh()->hub_tenant_id)->toBeNull()
        ->and($scope->fresh()->metadata['hub_account_attempt']['error']['reason'])->toBe('mode_mismatch');
});

test('every call made for a workspace names its own account and never the other one', function () {
    hubAccountsOn();
    hubAccountFake();
    [$tenantA, $scopeA, $credentialA, $agentA] = hubAccountWorkspace(account: 'acct-A');
    [, , $credentialB, $agentB] = hubAccountWorkspace(account: 'acct-B');
    $conversationA = hubAccountConversation($tenantA);
    $hub = app(AiAgentHubTenantService::class);

    // Agents, knowledge, credentials, flow test.
    $hub->updateAgent($agentA, ['name' => 'Atendimento 2']);
    $hub->createAgentKnowledge($agentA, ['title' => 'Planos', 'content' => 'R$ 90']);
    $hub->createProviderCredential($scopeA, ['provider' => 'OPENAI', 'name' => 'Outra', 'apiKey' => 'sk-test-12345678']);
    $hub->runFlowTest($agentA, 'flow-test-1', 'oi', 1, 'Ana');

    // Suggest reply: the same run, under its own conversation key, with voice.
    $hub->runAgent(
        $agentA,
        $conversationA,
        'qual o preço?',
        conversationExternalId: 'suggest:'.$conversationA->id.':m1',
        responseAudio: ['enabled' => true, 'voice' => 'alloy', 'format' => 'mp3'],
    );

    // Media generation.
    $generation = AiMediaGeneration::create([
        'tenant_id' => $tenantA->id,
        'ai_hub_provider_credential_id' => $credentialA->id,
        'external_id' => 'gen-'.uniqid(),
        'type' => 'image',
        'status' => AiMediaGeneration::STATUS_PENDING,
        'prompt' => 'um gato',
    ]);
    (new AiMediaGenerator)->start($generation);
    (new AiMediaGenerator)->refresh($generation->fresh());

    // Flow assistant: pushes the workspace's assistant agent, then runs.
    FlowAssistantSetting::create([
        'tenant_id' => $tenantA->id,
        'ai_hub_provider_credential_id' => $credentialA->id,
        'model' => 'gpt-4o-mini',
        'hub_agent_id' => 'agent-assistant',
        'agent_external_id' => 'Pingly_'.$scopeA->id.'_flow_assistant',
    ]);
    app(FlowAssistantService::class)->detectLanguage($tenantA, 'bom dia, quero saber o preço');

    // Token rental: the platform key is registered inside the workspace.
    CreditFixtures::poolKey();
    app(AiTokenRentalService::class)->rent($tenantA, 'OPENAI');

    // Ready-made agent hire.
    $category = TrainedAgentCategory::create(['name' => 'Vendas', 'slug' => 'vendas-'.uniqid()]);
    $blueprint = TrainedAgentBlueprint::create([
        'trained_agent_category_id' => $category->id,
        'name' => 'Vendedor',
        'slug' => 'vendedor-'.uniqid(),
        'model' => 'gpt-4o-mini',
        'system_prompt' => 'Você vende planos.',
        'price_cents' => 0,
        'currency' => 'BRL',
    ]);
    $hire = TrainedAgentHire::create([
        'tenant_id' => $tenantA->id,
        'trained_agent_blueprint_id' => $blueprint->id,
        'ai_hub_provider_credential_id' => $credentialA->id,
        'external_ref' => 'hire-'.uniqid(),
        'source' => HireSource::Included,
        'status' => HireStatus::Provisioning,
        'price_cents' => 0,
        'currency' => 'BRL',
        'blueprint_snapshot' => [
            'name' => 'Vendedor',
            'model' => 'gpt-4o-mini',
            'system_prompt' => 'Você vende planos.',
            'knowledge' => [['title' => 'Planos', 'content' => 'R$ 90']],
        ],
    ]);
    app(TrainedAgentService::class)->fulfill($hire);

    // Resync, as the command walks a workspace.
    $this->artisan('ai-hub:resync', ['--tenant' => $tenantA->id])->assertSuccessful();

    $forA = hubAccountRequests();
    $paths = implode("\n", array_map(fn ($row) => $row[0].' '.$row[1], $forA));

    // Every surface above actually reached the hub, so the loop below proves
    // something about each of them.
    expect($paths)
        ->toContain('PATCH /v1/agents/'.$agentA->hub_agent_id)
        ->toContain('POST /v1/agents/'.$agentA->hub_agent_id.'/knowledge')
        ->toContain('POST /v1/provider-credentials')
        ->toContain('POST /v1/runs')
        ->toContain('POST /v1/media-generations')
        ->toContain('GET /v1/media-generations/')
        ->toContain('PATCH /v1/agents/agent-assistant')
        ->toContain('POST /v1/agents')
        ->toContain('GET /v1/agents');

    expect(substr_count($paths, 'POST /v1/runs'))->toBeGreaterThanOrEqual(3);

    foreach ($forA as [$method, $path, $account]) {
        expect($account)->toBe('acct-A', "{$method} {$path} named the wrong account");
    }

    // And the other workspace's calls name only its own.
    $before = Http::recorded()->count();
    $hub->updateAgent($agentB, ['name' => 'Suporte B']);
    $hub->runFlowTest($agentB, 'flow-test-2', 'oi', 2, 'Bia');
    $hub->deleteProviderCredential($credentialB);

    $forB = hubAccountRequests($before);

    expect($forB)->toHaveCount(3);

    foreach ($forB as [$method, $path, $account]) {
        expect($account)->toBe('acct-B', "{$method} {$path} named the wrong account");
    }
});

test('a queued AI turn names the account of the workspace it answers for', function () {
    hubAccountsOn();
    hubAccountFake();
    [$conversation] = AiAgentFixtures::flow();
    $scope = AiHubTenant::query()->firstOrFail();
    $scope->update(['hub_tenant_id' => 'acct-flow', 'metadata' => ['hub_account' => ['mode' => 'shared']]]);

    AiAgentFixtures::openWithWelcome($conversation);
    AiAgentFixtures::incomingMedia($conversation, \App\Enums\Message\MessageType::Text, 'qual o preço?', null);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'qual o preço?');

    $runs = array_values(array_filter(hubAccountRequests(), fn ($row) => str_ends_with($row[1], '/runs')));

    expect($runs)->not->toBeEmpty();

    foreach ($runs as $run) {
        expect($run[2])->toBe('acct-flow');
    }
});

test('the model catalogue stays a platform-level call', function () {
    hubAccountsOn();
    hubAccountFake();
    [, $scope] = hubAccountWorkspace(account: 'acct-A');

    app(AiAgentHubTenantService::class)->listModels($scope);

    expect(hubAccountRequests()[0][2])->toBeNull();
});

test('a refused account is reported, not repaired, and never retried without the header', function () {
    hubAccountsOn();
    hubAccountFake(agentPatch: fn () => Http::response([
        'statusCode' => 403,
        'code' => 'hub_tenant_forbidden',
        'message' => 'Hub tenant is not an account of this platform',
    ], 403));
    [, , , $agent] = hubAccountWorkspace(account: 'acct-gone');

    expect(fn () => app(AiAgentHubTenantService::class)->updateAgent($agent, ['name' => 'X']))
        ->toThrow(UpstreamServiceException::class);

    $requests = hubAccountRequests();

    // One PATCH: no rebuild of the agent, no second try, nothing headerless.
    expect($requests)->toHaveCount(1)
        ->and($requests[0][0])->toBe('PATCH')
        ->and($requests[0][2])->toBe('acct-gone')
        ->and($agent->fresh()->name)->toBe('Atendimento');
});

test('a 404 about the account does not count as "already deleted"', function () {
    hubAccountsOn();
    Http::fake([
        'api-ia.ipbr.pro/*' => Http::response(['code' => 'hub_tenant_not_found', 'message' => 'Hub tenant not found'], 404),
    ]);
    [, , $credential] = hubAccountWorkspace(account: 'acct-gone');

    expect(fn () => app(AiAgentHubTenantService::class)->deleteProviderCredential($credential))
        ->toThrow(UpstreamServiceException::class)
        ->and(AiHubProviderCredential::find($credential->id))->not->toBeNull();
});

test('provisioning dry run lists the planned modes without calling the hub', function () {
    hubAccountFake();
    hubAccountWorkspace();
    hubAccountWorkspace(withData: false);
    hubAccountWorkspace(account: 'acct-done');

    $this->artisan('ai-hub:provision-accounts', ['--dry-run' => true])
        ->expectsOutputToContain('would open')
        ->expectsOutputToContain('has account')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('stale ids from before the hub was rebuilt are cleared, after a backup', function () {
    $storage = sys_get_temp_dir().'/hub-accounts-'.uniqid();
    File::ensureDirectoryExists($storage.'/app');
    $this->app->useStoragePath($storage);

    [, $stale] = hubAccountWorkspace(withData: false);
    $stale->update(['hub_tenant_id' => 'cmo-before-the-rebuild']);
    [, $opened] = hubAccountWorkspace(account: 'acct-opened-by-us', withData: false);

    $this->artisan('ai-hub:clear-stale-accounts', ['--dry-run' => true])->assertSuccessful();
    expect($stale->fresh()->hub_tenant_id)->toBe('cmo-before-the-rebuild');

    $this->artisan('ai-hub:clear-stale-accounts')->assertSuccessful();

    $backups = File::files($storage.'/app/backups');

    expect($stale->fresh()->hub_tenant_id)->toBeNull()
        ->and($opened->fresh()->hub_tenant_id)->toBe('acct-opened-by-us')
        ->and($backups)->toHaveCount(1)
        ->and(File::get($backups[0]->getPathname()))->toContain('cmo-before-the-rebuild');

    File::deleteDirectory($storage);
});

test('the back office reads and saves the switch', function () {
    $role = \Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bo.settings.manage', 'web'));
    $admin = \App\Models\Admin::factory()->create();
    $admin->assignRole($role);
    $admin = $admin->fresh();

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', ['ai_agent_hub' => ['per_workspace_accounts' => true]])
        ->assertOk()
        ->assertJsonPath('data.ai_agent_hub.per_workspace_accounts', true);

    expect(AiHubAccounts::enabled())->toBeTrue();

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', ['ai_agent_hub' => ['per_workspace_accounts' => false]])
        ->assertOk()
        ->assertJsonPath('data.ai_agent_hub.per_workspace_accounts', false);

    expect(AiHubAccounts::enabled())->toBeFalse();

    Cache::flush();
});
