<?php

use App\Models\AiHubProviderCredential;
use App\Services\AiCredits\AiTokenPool;
use App\Services\AiCredits\AiTokenRentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiCreditFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('rents a pool key by registering it in the workspace hub scope', function () {
    [$tenant] = AiCreditFixtures::workspace();
    $key = AiCreditFixtures::poolKey();

    $sent = null;
    Http::fake([
        '*/provider-credentials' => function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response([
                'id' => 'hub-cred-1',
                'provider' => 'OPENAI',
                'name' => 'rented',
                'keyPreview' => '••••0000',
                'status' => 'ACTIVE',
            ], 201);
        },
    ]);

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');

    // The platform's secret is what reached the hub — that is what "rented"
    // means here, because a hub credential belongs to a hub tenant and there is
    // no shared record several workspaces could point at.
    expect($sent['apiKey'])->toBe($key->api_key)
        ->and($sent['metadata']['ownerType'])->toBe('platform')
        ->and($credential->ai_token_pool_key_id)->toBe($key->id)
        ->and($credential->isRented())->toBeTrue();
});

it('never stores or returns the platform secret to the tenant', function () {
    [$tenant, $user] = AiCreditFixtures::workspace();
    AiCreditFixtures::poolKey();
    AiCreditFixtures::fakeHub();

    app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');

    $response = $this->actingAs($user)->getJson('/api/ai-hub/provider-credentials');

    $response->assertOk();

    expect(json_encode($response->json()))->not->toContain('sk-platform')
        ->and($response->json('data.0.is_rented'))->toBeTrue();
});

it('hands back the existing rental instead of renting a second key', function () {
    [$tenant] = AiCreditFixtures::workspace();
    AiCreditFixtures::poolKey();
    AiCreditFixtures::poolKey();
    AiCreditFixtures::fakeHub();

    $service = app(AiTokenRentalService::class);

    $first = $service->rent($tenant, 'OPENAI');
    $second = $service->rent($tenant, 'OPENAI');

    // Two entries for one key would be indistinguishable in the agent's
    // dropdown and would double the platform's exposure on this workspace for
    // nothing: the pool spreads load across workspaces, not within one.
    expect($second->id)->toBe($first->id)
        ->and(AiHubProviderCredential::rented()->count())->toBe(1);
});

it('spreads workspaces across the pool rather than stacking on one key', function () {
    AiCreditFixtures::poolKey();
    AiCreditFixtures::poolKey();
    AiCreditFixtures::poolKey();

    $picked = collect(range(1, 60))
        ->map(fn () => app(AiTokenPool::class)->pick('OPENAI')?->id)
        ->unique();

    // The whole reason the pick is random: a deterministic picker would put
    // every workspace on the same key and share one rate limit between them.
    expect($picked->count())->toBeGreaterThan(1);
});

it('honours the per-key tenant cap instead of overloading a key', function () {
    [$first] = AiCreditFixtures::workspace();
    [$second] = AiCreditFixtures::workspace();
    AiCreditFixtures::poolKey(['max_tenants' => 1]);
    AiCreditFixtures::fakeHub();

    app(AiTokenRentalService::class)->rent($first, 'OPENAI');

    // A provider's rate limit is per organisation, so an unbounded key means
    // one busy workspace throttling everyone sharing it.
    expect(fn () => app(AiTokenRentalService::class)->rent($second, 'OPENAI'))
        ->toThrow(RuntimeException::class);
});

it('refuses to edit or delete a rented credential through the ordinary endpoints', function () {
    [$tenant, $user] = AiCreditFixtures::workspace();
    AiCreditFixtures::poolKey();
    AiCreditFixtures::fakeHub();

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');

    // Both would reach the platform's own key: the PATCH would replace the
    // secret every workspace on it shares, the DELETE would drop the hub record
    // out from under them.
    $this->actingAs($user)
        ->patchJson("/api/ai-hub/provider-credentials/{$credential->id}", ['name' => 'mine now'])
        ->assertStatus(422);

    $this->actingAs($user)
        ->deleteJson("/api/ai-hub/provider-credentials/{$credential->id}")
        ->assertStatus(422);
});

it('refuses to release a rented key an agent still points at', function () {
    [$tenant, $user, $hubTenant] = AiCreditFixtures::workspace();
    AiCreditFixtures::poolKey();
    AiCreditFixtures::fakeHub();

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');
    AiCreditFixtures::agent($hubTenant, $credential->id);

    $this->actingAs($user)
        ->deleteJson("/api/ai-hub/rentals/{$credential->id}")
        ->assertStatus(422)
        // Naming the agent is what makes the refusal actionable rather than
        // something to argue with.
        ->assertSee('Suporte');
});

it('moves every workspace off a revoked key and re-points their agents', function () {
    [$tenant, , $hubTenant] = AiCreditFixtures::workspace();
    $doomed = AiCreditFixtures::poolKey(['label' => 'doomed']);
    $spare = AiCreditFixtures::poolKey(['label' => 'spare']);

    $credentials = 0;
    $agentPatch = null;

    Http::fake([
        '*/provider-credentials/*' => fn () => Http::response([], 200),
        '*/provider-credentials' => function () use (&$credentials) {
            $credentials++;

            return Http::response([
                'id' => "hub-cred-{$credentials}",
                'provider' => 'OPENAI',
                'name' => 'rented',
                'keyPreview' => '••••0000',
                'status' => 'ACTIVE',
            ], 201);
        },
        '*/agents/*' => function ($request) use (&$agentPatch) {
            $agentPatch = $request->data();

            return Http::response(['id' => 'hub-agent-1', 'providerCredentialId' => $agentPatch['providerCredentialId']], 200);
        },
    ]);

    $service = app(AiTokenRentalService::class);
    $original = $service->rent($tenant, 'OPENAI');

    // Force the rental onto the key about to be revoked, whichever way the
    // random draw went.
    $original->update(['ai_token_pool_key_id' => $doomed->id]);

    $agent = AiCreditFixtures::agent($hubTenant, $original->id);

    $result = $service->rotateAllFrom($doomed->fresh());

    $replacement = AiHubProviderCredential::rented()->latest('id')->first();

    expect($result['moved'])->toBe(1)
        ->and($replacement->ai_token_pool_key_id)->toBe($spare->id)
        // The agent has to move with it. Leaving it on the deleted credential
        // is the failure this path exists to avoid, and it would only surface
        // later, at run time, far from the revoke button.
        ->and($agent->fresh()->ai_hub_provider_credential_id)->toBe($replacement->id)
        ->and($agentPatch['providerCredentialId'])->toBe($replacement->hub_provider_credential_id);
});

it('rewrites the audio credential a flow node had pinned when it rotates', function () {
    [$tenant, , $hubTenant] = AiCreditFixtures::workspace();
    $doomed = AiCreditFixtures::poolKey(['label' => 'doomed']);
    AiCreditFixtures::poolKey(['label' => 'spare']);

    $credentials = 0;
    Http::fake([
        '*/provider-credentials/*' => fn () => Http::response([], 200),
        '*/provider-credentials' => function () use (&$credentials) {
            $credentials++;

            return Http::response([
                'id' => "hub-cred-{$credentials}",
                'provider' => 'OPENAI',
                'name' => 'rented',
                'keyPreview' => '••••0000',
                'status' => 'ACTIVE',
            ], 201);
        },
    ]);

    $service = app(AiTokenRentalService::class);
    $original = $service->rent($tenant, 'OPENAI');
    $original->update(['ai_token_pool_key_id' => $doomed->id]);

    $flow = \App\Models\Flow::create(['tenant_id' => $tenant->id, 'name' => 'Atendimento']);
    $node = \App\Models\FlowNode::create([
        'flow_id' => $flow->id,
        'type' => \App\Enums\Flow\NodeType::AIAgent,
        'data' => ['response_audio' => ['credential_id' => $original->hub_provider_credential_id]],
        'position_x' => 0,
        'position_y' => 0,
    ]);

    $service->rotate($original->fresh());

    $replacement = AiHubProviderCredential::rented()->latest('id')->first();

    // The node holds the *hub* id as a plain string, chosen in the node editor.
    // It does not look like a foreign key, which is exactly why forgetting it
    // would leave voice replies failing with no visible cause.
    expect($node->fresh()->data['response_audio']['credential_id'])
        ->toBe($replacement->hub_provider_credential_id);
});
