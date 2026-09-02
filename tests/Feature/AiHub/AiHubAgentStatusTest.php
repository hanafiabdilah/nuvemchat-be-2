<?php

use App\Models\AiHubProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreditFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * An agent is created running.
 *
 * The dashboard carried a `status` on the agent form from the day it was
 * written and never put it in the payload, so the hub applied its own default —
 * DRAFT — to every agent this product has ever created. The word appeared on
 * the row with nothing in the product able to explain or change it.
 */
it('creates an agent ACTIVE rather than leaving the hub to pick', function () {
    [, $user, $hubTenant] = CreditFixtures::workspace();

    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_provider_credential_id' => 'hub-cred-own',
        'provider' => 'OPENAI',
        'name' => 'Minha chave',
        'status' => 'ACTIVE',
    ]);

    $sent = null;
    Http::fake([
        '*/agents' => function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response([
                'id' => 'hub-agent-1',
                'externalId' => $sent['externalId'],
                'name' => $sent['name'],
                'model' => $sent['model'],
                'status' => $sent['status'] ?? 'DRAFT',
            ], 201);
        },
    ]);

    $this->actingAs($user)->postJson('/api/ai-hub/agents', [
        'providerCredentialId' => $credential->id,
        'name' => 'Suporte',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ])->assertCreated();

    expect($sent['status'])->toBe('ACTIVE');
});

it('lets an agent be switched off without deleting it', function () {
    [, $user, $hubTenant] = CreditFixtures::workspace();
    $agent = CreditFixtures::agent($hubTenant);

    $sent = null;
    Http::fake([
        '*/agents/*' => function ($request) use (&$sent, $agent) {
            $sent = $request->data();

            return Http::response(['id' => $agent->hub_agent_id, 'status' => $sent['status']], 200);
        },
    ]);

    // Off is a state, not a deletion: the prompt, the knowledge and the training
    // all survive, and the flow simply hands its conversations to a human.
    $this->actingAs($user)
        ->patchJson("/api/ai-hub/agents/{$agent->id}", ['status' => 'DISABLED'])
        ->assertOk();

    expect($sent['status'])->toBe('DISABLED')
        ->and($agent->fresh()->status)->toBe('DISABLED');
});
