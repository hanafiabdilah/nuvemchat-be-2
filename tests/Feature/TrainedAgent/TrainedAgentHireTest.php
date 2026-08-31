<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\TrainedAgent\HireSource;
use App\Enums\TrainedAgent\HireStatus;
use App\Jobs\TrainedAgent\FulfillTrainedAgentHire;
use App\Models\AiHubApiKey;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubTenant;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TrainedAgentBlueprint;
use App\Models\TrainedAgentCategory;
use App\Models\TrainedAgentHire;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionGate;
use App\Services\TrainedAgent\TrainedAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A workspace with the AI hub provisioned, one provider credential of its own,
 * and a plan carrying `$includedAgents` trained-agent slots.
 *
 * @return array{0: Tenant, 1: User, 2: AiHubProviderCredential}
 */
function trainedAgentWorkspace(int $includedAgents = 1): array
{
    $user = User::factory()->create(['email' => 'ta-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'features' => ['ai_agent_hub' => true, 'chat' => true],
        'quotas' => ['included_trained_agents' => $includedAgents],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        'quotas_snapshot' => ['included_trained_agents' => $includedAgents],
        'features_snapshot' => ['ai_agent_hub' => true, 'chat' => true],
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    $role = Role::findOrCreate('owner-'.$tenant->id, 'web');
    foreach (['ai-agents.view', 'ai-agents.create', 'billing.manage'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user->assignRole($role);

    $hubTenant = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => 'hub-tenant-'.uniqid(),
        'external_id' => 'Pingly_'.$tenant->id,
        'name' => 'Pingly_'.$tenant->id,
        'status' => 'ACTIVE',
    ]);

    AiHubApiKey::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_api_key_id' => 'hub-key-'.uniqid(),
        'name' => 'default',
        'api_key' => 'tenant-api-key',
        'status' => 'ACTIVE',
    ]);

    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_provider_credential_id' => 'hub-cred-'.uniqid(),
        'provider' => 'OPENAI',
        'name' => 'OpenAI',
        'status' => 'ACTIVE',
    ]);

    app(SubscriptionGate::class)->forget($tenant->fresh());

    return [$tenant->fresh(), $user->fresh(), $credential];
}

function trainedAgentBlueprint(array $overrides = []): TrainedAgentBlueprint
{
    $category = TrainedAgentCategory::create([
        'name' => 'Contabilidade', 'slug' => 'contabilidade-'.uniqid(),
    ]);

    return TrainedAgentBlueprint::create(array_merge([
        'trained_agent_category_id' => $category->id,
        'name' => 'Assistente Contábil',
        'slug' => 'assistente-contabil-'.uniqid(),
        'tagline' => 'Atende clientes de escritórios contábeis',
        'model' => 'gpt-4o-mini',
        'system_prompt' => 'Você é um assistente de um escritório de contabilidade.',
        'temperature' => 0.4,
        'max_tokens' => 800,
        'profile' => ['language' => 'pt-BR', 'tone' => 'formal', 'instructions' => ['Nunca dê consultoria fiscal definitiva.']],
        'knowledge' => [
            ['title' => 'Prazos do Simples Nacional', 'content' => 'DAS vence dia 20.', 'tags' => ['fiscal']],
            ['title' => 'Documentos de abertura', 'content' => 'RG, CPF, comprovante.'],
        ],
        'skills' => [['name' => 'Agendar reunião', 'description' => 'Marca horário com o contador.']],
        'training_examples' => [['input' => 'Quando vence o DAS?', 'expected_output' => 'Todo dia 20.']],
        'price_cents' => 14900,
        'currency' => 'BRL',
    ], $overrides));
}

/**
 * Every hub call the fork makes.
 *
 * The ids come from closures, not from pre-built responses: `Http::response()`
 * is evaluated once, so a literal `uniqid()` would hand the same id to every
 * knowledge row and trip the local unique index on the second one.
 *
 * `$failKnowledgeOnce` reproduces a fork that dies halfway, which is the case
 * the resume logic exists for.
 */
function fakeHubFork(bool $failKnowledgeOnce = false): void
{
    $knowledgeCalls = 0;

    Http::fake([
        '*/agents/*/knowledge' => function () use (&$knowledgeCalls, $failKnowledgeOnce) {
            $knowledgeCalls++;

            if ($failKnowledgeOnce && $knowledgeCalls === 1) {
                return Http::response(['error' => 'boom'], 500);
            }

            return Http::response(['id' => 'k-'.uniqid()], 201);
        },
        '*/agents/*/skills' => fn () => Http::response(['id' => 's-'.uniqid()], 201),
        '*/agents/*/training-examples' => fn () => Http::response(['id' => 'e-'.uniqid()], 201),
        '*/agents/*/profile' => fn () => Http::response(['language' => 'pt-BR'], 200),
        // The hub echoes the agent back, and the local mirror is built from
        // that echo — so the fake has to echo too, or nothing is persisted.
        '*/agents' => fn ($request) => Http::response([
            'id' => 'hub-agent-forked',
            'externalId' => 'Pingly_assistente',
            'name' => $request['name'] ?? 'Assistente Contábil',
            'model' => $request['model'] ?? 'gpt-4o-mini',
            'systemPrompt' => $request['systemPrompt'] ?? null,
            'temperature' => $request['temperature'] ?? null,
            'maxTokens' => $request['maxTokens'] ?? null,
            'metadata' => $request['metadata'] ?? null,
            'status' => 'ACTIVE',
        ], 201),
        '*/tenants*' => fn () => Http::response(['id' => 'hub-tenant-x'], 200),
    ]);
}

test('an included hire is free, spends a plan slot and queues the fork', function () {
    Bus::fake();
    [$tenant, $user, $credential] = trainedAgentWorkspace(includedAgents: 2);
    $blueprint = trainedAgentBlueprint();

    $result = app(TrainedAgentService::class)->hire($tenant, $blueprint, $credential->id);

    $hire = $result['hire'];

    expect($hire->source)->toBe(HireSource::Included)
        ->and($hire->status)->toBe(HireStatus::Provisioning)
        ->and($hire->price_cents)->toBe(0)
        ->and($result['invoice'])->toBeNull()
        // The snapshot is what was sold — it has to survive a later edit.
        ->and($hire->blueprint_snapshot['system_prompt'])->toBe($blueprint->system_prompt);

    Bus::assertDispatched(FulfillTrainedAgentHire::class);

    app(SubscriptionGate::class)->forget($tenant);
    expect(app(SubscriptionGate::class)->trainedAgentsUsed($tenant))->toBe(1);
});

test('hiring past the included allowance creates a pix charge instead', function () {
    Bus::fake();
    [$tenant, $user, $credential] = trainedAgentWorkspace(includedAgents: 1);
    $blueprint = trainedAgentBlueprint();

    $service = app(TrainedAgentService::class);
    $service->hire($tenant, $blueprint, $credential->id);

    app(SubscriptionGate::class)->forget($tenant);

    // The second one has to be paid for. MercadoPago is the only outbound call.
    Http::fake(['*/v1/payments*' => Http::response([
        'id' => 987654,
        'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => [
            'qr_code' => '00020126',
            'qr_code_base64' => 'BASE64',
        ]],
    ], 201)]);

    $result = $service->hire($tenant, $blueprint, $credential->id);

    expect($result['hire']->source)->toBe(HireSource::Purchased)
        ->and($result['hire']->status)->toBe(HireStatus::PendingPayment)
        ->and($result['hire']->price_cents)->toBe(14900)
        ->and($result['invoice']->purpose)->toBe(InvoicePurpose::TrainedAgentPurchase)
        ->and($result['invoice']->trained_agent_hire_id)->toBe($result['hire']->id)
        ->and($result['invoice']->pix_copy_paste)->toBe('00020126');

    // A purchase must never quietly eat a plan slot on top of the money.
    app(SubscriptionGate::class)->forget($tenant);
    expect(app(SubscriptionGate::class)->trainedAgentsUsed($tenant))->toBe(1);
});

test('a free blueprint outside the allowance is refused rather than sold for nothing', function () {
    Bus::fake();
    [$tenant, $user, $credential] = trainedAgentWorkspace(includedAgents: 0);
    $blueprint = trainedAgentBlueprint(['price_cents' => 0]);

    expect(fn () => app(TrainedAgentService::class)->hire($tenant, $blueprint, $credential->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(TrainedAgentHire::count())->toBe(0);
});

test('a credential from another workspace is refused', function () {
    Bus::fake();
    [$tenant, , ] = trainedAgentWorkspace();
    [, , $otherCredential] = trainedAgentWorkspace();
    $blueprint = trainedAgentBlueprint();

    expect(fn () => app(TrainedAgentService::class)->hire($tenant, $blueprint, $otherCredential->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('the paid webhook moves the hire to provisioning and queues the fork', function () {
    Bus::fake();
    [$tenant, , $credential] = trainedAgentWorkspace(includedAgents: 0);
    $blueprint = trainedAgentBlueprint();

    Http::fake(['*/v1/payments*' => Http::response([
        'id' => 987654,
        'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
    ], 201)]);

    $result = app(TrainedAgentService::class)->hire($tenant, $blueprint, $credential->id);

    app(BillingService::class)->applyPaymentUpdate([
        'id' => (string) $result['invoice']->mp_payment_id,
        'status' => 'approved',
    ]);

    expect($result['invoice']->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($result['hire']->fresh()->status)->toBe(HireStatus::Provisioning);

    Bus::assertDispatched(FulfillTrainedAgentHire::class);
});

test('fulfilment forks the whole blueprint into the tenant workspace', function () {
    [$tenant, , $credential] = trainedAgentWorkspace(includedAgents: 1);
    $blueprint = trainedAgentBlueprint();

    Bus::fake();
    $hire = app(TrainedAgentService::class)->hire($tenant, $blueprint, $credential->id)['hire'];

    fakeHubFork();
    app(TrainedAgentService::class)->fulfill($hire->fresh());

    $hire->refresh();
    $agent = $hire->agent;

    expect($hire->status)->toBe(HireStatus::Active)
        ->and($hire->hired_at)->not->toBeNull()
        ->and($agent)->not->toBeNull()
        ->and($agent->system_prompt)->toBe($blueprint->system_prompt)
        // Provenance, so a prompt nobody wrote is explainable months later.
        ->and($agent->metadata['source'] ?? null)->toBe('trained_agent')
        ->and($agent->knowledge()->count())->toBe(2)
        ->and($agent->skills()->count())->toBe(1)
        ->and($agent->trainingExamples()->count())->toBe(1)
        ->and($agent->profile)->not->toBeNull();
});

test('a resumed fulfilment continues instead of creating a second agent', function () {
    [$tenant, , $credential] = trainedAgentWorkspace(includedAgents: 1);
    $blueprint = trainedAgentBlueprint();

    Bus::fake();
    $hire = app(TrainedAgentService::class)->hire($tenant, $blueprint, $credential->id)['hire'];

    // First attempt: the agent and profile land, the first knowledge item
    // blows up. Re-running must continue, not mint a second agent.
    fakeHubFork(failKnowledgeOnce: true);

    expect(fn () => app(TrainedAgentService::class)->fulfill($hire->fresh()))->toThrow(\Exception::class);

    $agentId = $hire->fresh()->ai_hub_agent_id;
    expect($agentId)->not->toBeNull();

    app(TrainedAgentService::class)->fulfill($hire->fresh());

    $hire->refresh();

    expect($hire->ai_hub_agent_id)->toBe($agentId)
        ->and(\App\Models\AiHubAgent::count())->toBe(1)
        ->and($hire->agent->knowledge()->count())->toBe(2)
        ->and($hire->status)->toBe(HireStatus::Active);
});

test('a failed included fork releases the slot and is not flagged for refund', function () {
    Bus::fake();
    [$tenant, , $credential] = trainedAgentWorkspace(includedAgents: 1);
    $blueprint = trainedAgentBlueprint();

    $service = app(TrainedAgentService::class);
    $included = $service->hire($tenant, $blueprint, $credential->id)['hire'];
    $service->markFailed($included->fresh(), 'hub down');

    expect($included->fresh()->status)->toBe(HireStatus::Failed)
        ->and($included->fresh()->needsAttention())->toBeFalse();

    // A failed fork must not keep holding the slot it never delivered.
    app(SubscriptionGate::class)->forget($tenant);
    expect(app(SubscriptionGate::class)->trainedAgentsUsed($tenant))->toBe(0);
});

test('a failed paid fork is flagged for refund', function () {
    Bus::fake();
    [$tenant, , $credential] = trainedAgentWorkspace(includedAgents: 0);
    $blueprint = trainedAgentBlueprint();

    Http::fake(['*/v1/payments*' => Http::response([
        'id' => 111, 'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
    ], 201)]);

    $service = app(TrainedAgentService::class);
    $paid = $service->hire($tenant, $blueprint, $credential->id)['hire'];
    $paid->update(['status' => HireStatus::Provisioning]);
    $service->markFailed($paid->fresh(), 'hub down');

    expect($paid->fresh()->needsAttention())->toBeTrue()
        ->and(TrainedAgentHire::query()->needsAttention()->count())->toBe(1);
});

test('the catalog never ships the prompt or knowledge bodies to a tenant', function () {
    [$tenant, $user, ] = trainedAgentWorkspace();
    $blueprint = trainedAgentBlueprint();

    $response = $this->actingAs($user)->getJson('/api/trained-agents');

    $response->assertOk();

    $payload = $response->json('data.0');

    expect($payload)->not->toHaveKey('system_prompt')
        ->and($payload['contents']['knowledge'])->toBe(2)
        ->and($payload['knowledge_titles'])->toContain('Prazos do Simples Nacional')
        ->and(json_encode($response->json()))->not->toContain('DAS vence dia 20');
});

test('abandoning an unpaid hire voids its charge and removes the row', function () {
    Bus::fake();
    [$tenant, , $credential] = trainedAgentWorkspace(includedAgents: 0);
    $blueprint = trainedAgentBlueprint();

    Http::fake([
        '*/v1/payments/*' => Http::response(['id' => 222, 'status' => 'cancelled'], 200),
        '*/v1/payments*' => Http::response([
            'id' => 222, 'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
        ], 201),
    ]);

    $result = app(TrainedAgentService::class)->hire($tenant, $blueprint, $credential->id);

    expect(app(TrainedAgentService::class)->abandonPending($result['hire']->fresh()))->toBeTrue()
        ->and(TrainedAgentHire::find($result['hire']->id))->toBeNull()
        // The charge record survives with a null FK — an audit trail, not a card.
        ->and(Invoice::find($result['invoice']->id)->status)->toBe(InvoiceStatus::Cancelled);
});
