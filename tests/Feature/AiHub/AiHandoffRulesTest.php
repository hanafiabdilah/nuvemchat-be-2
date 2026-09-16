<?php

use App\Models\AiHubAgent;
use App\Models\AiHubTenant;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function handoffTenant(): AiHubTenant
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    return AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'external_id' => 'Pingly_1',
        'name' => 'Pingly_1',
        'status' => 'ACTIVE',
    ]);
}

/** The hub echoes whatever it was sent, which is what it really does. */
function fakeHubAgent(): void
{
    Http::fake([
        'api-ia.ipbr.pro/*' => function ($request) {
            return Http::response(array_merge(
                ['id' => 'hub-agent-1', 'status' => 'ACTIVE'],
                $request->data(),
            ));
        },
    ]);
}

/** The handoffRules of the last agent call the hub received. */
function sentRules(): ?array
{
    $rules = null;

    foreach (Http::recorded() as [$request]) {
        $data = $request->data();
        if (array_key_exists('handoffRules', $data)) {
            $rules = $data['handoffRules'];
        }
    }

    return $rules;
}

function handoffAgent(AiHubTenant $hubTenant, array $rules): AiHubAgent
{
    return $hubTenant->agents()->create([
        'hub_agent_id' => 'hub-agent-1',
        'external_id' => 'Pingly_1_atendimento',
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
        'handoff_rules' => $rules,
    ]);
}

it('tells the hub to switch its automatic detector off along with the keyword rule', function () {
    $hubTenant = handoffTenant();
    fakeHubAgent();

    app(AiAgentHubTenantService::class)->createAgent($hubTenant, [
        'externalId' => 'atendimento',
        'name' => 'Atendimento',
        'handoffRules' => ['humanRequested' => false, 'angryCustomer' => false, 'outOfScope' => false],
    ]);

    // `humanRequested: false` alone only disables the keyword rule; the hub's
    // automatic detection keeps handing conversations to people until this.
    expect(sentRules())->toBe([
        'humanRequested' => false,
        'angryCustomer' => false,
        'outOfScope' => false,
        'autoDetectHumanRequest' => false,
    ]);
});

it('keeps the detector on for a workspace that wants the keyword rule', function () {
    $hubTenant = handoffTenant();
    fakeHubAgent();

    app(AiAgentHubTenantService::class)->createAgent($hubTenant, [
        'externalId' => 'atendimento',
        'name' => 'Atendimento',
        'handoffRules' => ['humanRequested' => true, 'angryCustomer' => false, 'outOfScope' => false],
    ]);

    expect(sentRules()['autoDetectHumanRequest'])->toBeTrue();
});

it('overwrites a stale value rather than defaulting around it', function () {
    $hubTenant = handoffTenant();
    fakeHubAgent();

    // What the dashboard sends: the rules it loaded, with one toggle flipped.
    // The old `autoDetectHumanRequest` rides along, and left alone it would
    // keep the detector off for a workspace that just switched the rule on.
    app(AiAgentHubTenantService::class)->updateAgent(handoffAgent($hubTenant, []), [
        'handoffRules' => [
            'humanRequested' => true,
            'angryCustomer' => false,
            'outOfScope' => false,
            'autoDetectHumanRequest' => false,
        ],
    ]);

    expect(sentRules()['autoDetectHumanRequest'])->toBeTrue();
});

it('does not start writing handoff rules for a request that never mentioned them', function () {
    $hubTenant = handoffTenant();
    fakeHubAgent();

    app(AiAgentHubTenantService::class)->updateAgent(
        handoffAgent($hubTenant, ['humanRequested' => false]),
        ['name' => 'Atendimento 2'],
    );

    expect(sentRules())->toBeNull();
});

it('carries the mirror through the self-healing repush', function () {
    $hubTenant = handoffTenant();
    fakeHubAgent();

    app(AiAgentHubTenantService::class)->repushAgent(
        handoffAgent($hubTenant, ['humanRequested' => false, 'angryCustomer' => true, 'outOfScope' => false])
    );

    expect(sentRules()['autoDetectHumanRequest'])->toBeFalse()
        ->and(sentRules()['angryCustomer'])->toBeTrue();
});

it('re-sends the rules of agents already on the hub', function () {
    $hubTenant = handoffTenant();
    fakeHubAgent();

    handoffAgent($hubTenant, ['humanRequested' => false, 'angryCustomer' => false, 'outOfScope' => false]);

    // Deploying the mirror changes nothing by itself: handoffRules only travel
    // when an agent is written, so an agent already on the hub keeps the state
    // that is handing conversations to people.
    $this->artisan('ai-hub:sync-handoff-rules')->assertSuccessful();

    expect(sentRules()['autoDetectHumanRequest'])->toBeFalse();
});

it('leaves agents with no stored rules alone rather than inventing them', function () {
    $hubTenant = handoffTenant();
    fakeHubAgent();

    handoffAgent($hubTenant, []);

    $this->artisan('ai-hub:sync-handoff-rules')
        ->expectsOutputToContain('No agents with handoff rules to sync.')
        ->assertSuccessful();

    expect(Http::recorded())->toHaveCount(0);
});
