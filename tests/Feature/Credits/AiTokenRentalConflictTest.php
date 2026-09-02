<?php

use App\Models\AiHubProviderCredential;
use App\Services\AiTokens\AiTokenRentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreditFixtures;

uses(RefreshDatabase::class);

/**
 * The hub keys provider credentials on (tenant, provider, name) and answers a
 * repeat with 409.
 *
 * The rental suite's own fake did not model that, and the omission hid two real
 * failures: every rotation collided (it deliberately holds the replacement and
 * the outgoing credential at once), and a workspace whose local row was lost
 * could never rent that provider again. This file exists to keep that rule in
 * the tests, not just in production.
 *
 * @param  array<int, array<string, mixed>>  $store  seeded rows, and where new ones land
 */
function fakeHubWithUniqueNames(array &$store): void
{
    Http::fake([
        '*/provider-credentials/*' => fn () => Http::response([], 200),
        // Rotation re-points the agent hub-side. Without this the PATCH is a
        // stray request and the rotation fails for a reason that has nothing to
        // do with what this file is testing.
        '*/agents/*' => fn ($request) => Http::response([
            'id' => 'hub-agent-1',
            'providerCredentialId' => $request->data()['providerCredentialId'] ?? null,
        ], 200),
        '*/provider-credentials' => function ($request) use (&$store) {
            if ($request->method() === 'GET') {
                return Http::response($store, 200);
            }

            $payload = $request->data();

            foreach ($store as $existing) {
                if ($existing['provider'] === $payload['provider'] && $existing['name'] === $payload['name']) {
                    return Http::response([
                        'message' => 'Provider credential already exists for this tenant, provider and name.',
                        'error' => 'Conflict',
                        'statusCode' => 409,
                    ], 409);
                }
            }

            $row = [
                'id' => 'hub-cred-' . (count($store) + 1) . '-' . uniqid(),
                'provider' => $payload['provider'],
                'name' => $payload['name'],
                'keyPreview' => '••••0000',
                'status' => 'ACTIVE',
                'metadata' => $payload['metadata'] ?? null,
            ];

            $store[] = $row;

            return Http::response($row, 201);
        },
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('rotates against a hub that refuses duplicate names', function () {
    [$tenant, , $hubTenant] = CreditFixtures::workspace();
    $doomed = CreditFixtures::poolKey(['label' => 'doomed']);
    $spare = CreditFixtures::poolKey(['label' => 'spare']);

    $store = [];
    fakeHubWithUniqueNames($store);

    $service = app(AiTokenRentalService::class);
    $original = $service->rent($tenant, 'OPENAI');
    $original->update(['ai_token_pool_key_id' => $doomed->id]);

    CreditFixtures::agent($hubTenant, $original->id);

    // Before the name carried a random tail this returned {moved: 0, failed: 1}
    // for every workspace on the key — a revoke that reported success while
    // leaving everyone on a dead secret.
    $result = $service->rotateAllFrom($doomed->fresh());

    expect($result)->toBe(['moved' => 1, 'failed' => 0])
        ->and(AiHubProviderCredential::rented()->latest('id')->first()->ai_token_pool_key_id)
        ->toBe($spare->id);
});

it('adopts the hub credential it already has instead of getting stuck on 409', function () {
    [$tenant] = CreditFixtures::workspace();
    $key = CreditFixtures::poolKey();

    // A rental the hub kept and we lost: a half-finished attempt, or a local
    // database restored from before it. Named the old way, on purpose — the
    // rows this unsticks in production are exactly the ones minted before the
    // name changed.
    $store = [[
        'id' => 'hub-cred-orphan',
        'provider' => 'OPENAI',
        'name' => config('app.name') . ' — OPENAI (alugado)',
        'keyPreview' => '••••0000',
        'status' => 'ACTIVE',
        'metadata' => ['ownerType' => 'platform'],
    ]];

    fakeHubWithUniqueNames($store);

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');

    // Adopted, not duplicated: creating a second one under a fresh name would
    // also have "worked", and left an orphan nothing points at.
    expect($credential->hub_provider_credential_id)->toBe('hub-cred-orphan')
        ->and($credential->isRented())->toBeTrue()
        ->and($credential->ai_token_pool_key_id)->toBe($key->id)
        ->and(AiHubProviderCredential::count())->toBe(1);
});

it('never adopts a credential the workspace pasted itself', function () {
    [$tenant] = CreditFixtures::workspace();
    CreditFixtures::poolKey();

    // The customer's own key, sitting in the same hub list. Adopting it would
    // put their private key under our billing and let us delete it out from
    // under them.
    $store = [[
        'id' => 'hub-cred-theirs',
        'provider' => 'OPENAI',
        'name' => 'Minha chave da empresa',
        'keyPreview' => '••••9999',
        'status' => 'ACTIVE',
        'metadata' => ['ownerType' => 'customer'],
    ]];

    fakeHubWithUniqueNames($store);

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');

    expect($credential->hub_provider_credential_id)->not->toBe('hub-cred-theirs')
        ->and($credential->isRented())->toBeTrue();
});

it('lets two workspaces rent the same provider without colliding', function () {
    [$first] = CreditFixtures::workspace();
    [$second] = CreditFixtures::workspace();
    CreditFixtures::poolKey();

    // One shared fake stands in for a hub that scopes names per tenant; the
    // point is that our own names do not collide even under the stricter rule.
    $store = [];
    fakeHubWithUniqueNames($store);

    $service = app(AiTokenRentalService::class);
    $a = $service->rent($first, 'OPENAI');
    $b = $service->rent($second, 'OPENAI');

    expect($a->hub_provider_credential_id)->not->toBe($b->hub_provider_credential_id)
        // Both still read the same in the dropdown: the hub needs uniqueness,
        // the customer needs a sentence.
        ->and($a->name)->toBe($b->name);
});

it('reuses the rental for a provider instead of minting a second one', function () {
    [$tenant] = CreditFixtures::workspace();
    CreditFixtures::poolKey();
    CreditFixtures::poolKey();

    $store = [];
    fakeHubWithUniqueNames($store);

    $service = app(AiTokenRentalService::class);

    // Two agents on the same rented provider — the second save must find the
    // rental the first one made, not create a credential the workspace could
    // never tell apart from it in a dropdown.
    $first = $service->rent($tenant, 'OPENAI');
    $second = $service->rent($tenant, 'OPENAI');
    $third = $service->rent($tenant, 'openai');

    expect($second->id)->toBe($first->id)
        // Case is not identity: the provider arrives uppercased from the pool
        // and lowercased from a hand-written request.
        ->and($third->id)->toBe($first->id)
        ->and(AiHubProviderCredential::rented()->count())->toBe(1)
        ->and($store)->toHaveCount(1);
});

it('never adopts a credential the hub has disabled', function () {
    [$tenant] = CreditFixtures::workspace();
    CreditFixtures::poolKey();

    // Adopting this succeeds and then poisons every agent built on it: the hub
    // answers "Provider credential not found or disabled" at save time, which
    // is a sentence about its bookkeeping shown to someone naming an agent.
    $store = [[
        'id' => 'hub-cred-dead',
        'provider' => 'OPENAI',
        'name' => config('app.name') . ' — OPENAI (alugado)',
        'keyPreview' => '••••0000',
        'status' => 'DISABLED',
        'metadata' => ['ownerType' => 'platform'],
    ]];

    fakeHubWithUniqueNames($store);

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');

    expect($credential->hub_provider_credential_id)->not->toBe('hub-cred-dead')
        ->and($credential->status)->toBe('ACTIVE');
});

it('re-mints a rental the hub no longer has, and moves the agents with it', function () {
    [$tenant, , $hubTenant] = CreditFixtures::workspace();
    $key = CreditFixtures::poolKey();

    $store = [];
    fakeHubWithUniqueNames($store);

    $service = app(AiTokenRentalService::class);
    $credential = $service->rent($tenant, 'OPENAI');
    $agent = CreditFixtures::agent($hubTenant, $credential->id);

    // The hub lost it — a reset, or somebody disabling it there. We still hold
    // the key, so unlike a customer's own credential this one can be rebuilt.
    $store = [];
    fakeHubWithUniqueNames($store);

    $repaired = $service->ensureUsable($credential->fresh());

    expect($repaired->id)->not->toBe($credential->id)
        ->and($repaired->ai_token_pool_key_id)->toBe($key->id)
        // The agent has to come along, or the repair just moves the failure.
        ->and($agent->fresh()->ai_hub_provider_credential_id)->toBe($repaired->id)
        ->and(AiHubProviderCredential::whereKey($credential->id)->exists())->toBeFalse();
});

it('leaves a healthy rental alone', function () {
    [$tenant] = CreditFixtures::workspace();
    CreditFixtures::poolKey();

    $store = [];
    fakeHubWithUniqueNames($store);

    $service = app(AiTokenRentalService::class);
    $credential = $service->rent($tenant, 'OPENAI');

    // Nothing is wrong, so nothing is minted: a verification that re-mints on
    // every save would churn hub records and re-point agents for no reason.
    expect($service->ensureUsable($credential->fresh())->id)->toBe($credential->id)
        ->and($store)->toHaveCount(1);
});
