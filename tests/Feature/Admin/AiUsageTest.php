<?php

use App\Exceptions\Billing\AiRunQuotaExceededException;
use App\Models\AiHubAgent;
use App\Models\AiHubApiKey;
use App\Models\AiHubRun;
use App\Models\AiHubTenant;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function aiUsageAdmin(): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.ai-usage.view', 'web'));

    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole($role);

    return $user;
}

/** A tenant with an AI agent wired up, on a plan with $runLimit AI runs. */
function tenantWithAiAgent(?int $runLimit = null): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->update(['tenant_id' => $tenant->id]);

    $plan = Plan::create([
        'name' => 'AI plan',
        'slug' => 'ai-'.uniqid(),
        'price_cents' => 9900,
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true, 'ai_agent_hub' => true],
        'quotas' => $runLimit === null ? [] : ['max_ai_runs' => $runLimit],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'features_snapshot' => $plan->features,
        'quotas_snapshot' => $plan->quotas,
        'current_period_start' => now()->subDays(3),
        'current_period_end' => now()->addDays(27),
    ]);

    $tenant->update(['current_subscription_id' => $subscription->id]);

    $hubTenant = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => 'hub-'.uniqid(),
        'external_id' => 'ext-'.uniqid(),
        'name' => 'Hub tenant',
    ]);

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'agent-'.uniqid(),
        'name' => 'Support agent',
    ]);

    return [$tenant->fresh(), $agent];
}

function aiRun(Tenant $tenant, array $attributes = []): AiHubRun
{
    $hubTenant = AiHubTenant::firstWhere('tenant_id', $tenant->id)
        ?? AiHubTenant::create([
            'tenant_id' => $tenant->id,
            'hub_tenant_id' => 'hub-'.uniqid(),
            'external_id' => 'ext-'.uniqid(),
            'name' => 'Hub tenant',
        ]);

    $agent = AiHubAgent::firstWhere('ai_hub_tenant_id', $hubTenant->id)
        ?? AiHubAgent::create([
            'ai_hub_tenant_id' => $hubTenant->id,
            'hub_agent_id' => 'agent-'.uniqid(),
            'name' => 'Agent',
        ]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'name' => 'WA',
        'channel' => 'whatsapp_official',
        'status' => 'active',
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => 'c-'.uniqid(),
        'name' => 'Customer',
    ]);

    $conversation = Conversation::create([
        'connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_id' => 'conv-'.uniqid(),
        'status' => 'active',
    ]);

    return AiHubRun::create(array_merge([
        'tenant_id' => $tenant->id,
        'ai_hub_agent_id' => $agent->id,
        'conversation_id' => $conversation->id,
        'hub_run_id' => 'run-'.uniqid(),
        'status' => 'COMPLETED',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'input_tokens' => 100,
        'output_tokens' => 50,
        'total_tokens' => 150,
        'cost_usd' => 0.002,
    ], $attributes));
}

test('the usage endpoint totals spend, tokens and failures', function () {
    [$tenant] = tenantWithAiAgent();

    aiRun($tenant);
    aiRun($tenant, ['cost_usd' => 0.004, 'total_tokens' => 300]);
    aiRun($tenant, ['error' => ['message' => 'provider timeout'], 'status' => 'FAILED']);

    $res = $this->actingAs(aiUsageAdmin(), 'sanctum')
        ->getJson('/api/admin/ai-usage?days=30')
        ->assertOk();

    expect($res->json('data.totals.runs'))->toBe(3);
    expect($res->json('data.totals.cost_usd'))->toEqualWithDelta(0.008, 0.000001);
    expect($res->json('data.totals.failed'))->toBe(1);
    expect($res->json('data.errors.0.error'))->toBe('provider timeout');
});

test('runs with no reported cost are counted but flagged, not silently averaged in', function () {
    [$tenant] = tenantWithAiAgent();

    aiRun($tenant, ['cost_usd' => null]);
    aiRun($tenant, ['cost_usd' => 0.01]);

    $res = $this->actingAs(aiUsageAdmin(), 'sanctum')->getJson('/api/admin/ai-usage')->assertOk();

    // A spend figure covering half the runs is only trustworthy if the reader
    // can see that it covers half the runs.
    expect($res->json('data.totals.runs'))->toBe(2);
    expect($res->json('data.totals.costed_runs'))->toBe(1);
});

test('the series seeds empty buckets so an outage is visible as a gap', function () {
    [$tenant] = tenantWithAiAgent();
    aiRun($tenant);

    $res = $this->actingAs(aiUsageAdmin(), 'sanctum')->getJson('/api/admin/ai-usage?days=7')->assertOk();

    $series = $res->json('data.series');

    expect(count($series))->toBeGreaterThan(1);
    expect(collect($series)->pluck('runs')->sum())->toBe(1);
});

test('the biggest spender is identifiable by name', function () {
    [$tenant] = tenantWithAiAgent();
    aiRun($tenant, ['cost_usd' => 5.0]);

    $res = $this->actingAs(aiUsageAdmin(), 'sanctum')->getJson('/api/admin/ai-usage')->assertOk();

    expect($res->json('data.by_tenant.0.tenant_id'))->toBe($tenant->id);
    expect($res->json('data.by_tenant.0.cost_usd'))->toEqualWithDelta(5.0, 0.000001);
    expect($res->json('data.by_model.0.model'))->toBe('gpt-4o-mini');
});

test('an admin without the permission cannot read platform AI spend', function () {
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole($role);

    $this->actingAs($user, 'sanctum')->getJson('/api/admin/ai-usage')->assertForbidden();
});

test('a tenant over its AI run quota is refused before the hub is called', function () {
    config()->set('services.mercadopago.enforce', true);
    Http::fake();

    [$tenant, $agent] = tenantWithAiAgent(runLimit: 2);
    aiRun($tenant);
    aiRun($tenant);

    $conversation = Conversation::first();

    expect(fn () => app(AiAgentHubTenantService::class)->runAgent($agent, $conversation, 'hello'))
        ->toThrow(AiRunQuotaExceededException::class);

    // The point of checking before the call: an over-quota workspace costs nothing.
    Http::assertNothingSent();
});

test('a plan with no AI run quota is unlimited', function () {
    config()->set('services.mercadopago.enforce', true);

    [$tenant] = tenantWithAiAgent(runLimit: null);
    aiRun($tenant);

    expect(app(SubscriptionGate::class)->canRunAi($tenant->fresh()))->toBeTrue();
});

test('AI runs are counted within the billing period, not the calendar month', function () {
    [$tenant] = tenantWithAiAgent(runLimit: 5);

    // A run from the previous period must not eat into this period's allowance.
    aiRun($tenant)->forceFill(['created_at' => now()->subDays(10)])->save();
    aiRun($tenant);

    expect(app(SubscriptionGate::class)->aiRunsUsed($tenant->fresh()))->toBe(1);
});

test('enforcement follows the billing master switch', function () {
    config()->set('services.mercadopago.enforce', false);
    Http::fake(['*' => Http::response(['id' => 'r1', 'status' => 'COMPLETED', 'output' => ['message' => 'hi']], 200)]);

    [$tenant, $agent] = tenantWithAiAgent(runLimit: 1);
    aiRun($tenant);

    AiHubApiKey::create([
        'ai_hub_tenant_id' => $agent->ai_hub_tenant_id,
        'hub_api_key_id' => 'key-'.uniqid(),
        'api_key' => 'secret',
        'status' => 'ACTIVE',
    ]);

    // Already over the limit, but with enforcement off quotas are advisory —
    // the same master switch every other entitlement check follows.
    $run = app(AiAgentHubTenantService::class)->runAgent($agent, Conversation::first(), 'hello');

    expect($run->hub_run_id)->toBe('r1');
});
