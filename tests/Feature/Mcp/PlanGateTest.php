<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\McpFixtures;

uses(RefreshDatabase::class);

/**
 * The plan gate, with billing actually enforced.
 *
 * ⚠️ `BILLING_ENFORCE` is false in the test environment, so every other test in
 * this suite runs straight past these two checks. That is exactly how the first
 * production connection failed while the suite was green: the whole gate was
 * untested. These tests turn it on.
 */
beforeEach(fn () => config(['mcp.enabled' => true, 'services.billing.enforce' => true]));

it('refuses at the consent screen when the plan does not include MCP', function () {
    // Every flow feature, no `mcp` — the shape of every plan that existed
    // before the feature was added.
    $user = McpFixtures::user(features: ['chat' => true, 'flow' => true]);
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $query = http_build_query([
        'client_id' => $client->client_id,
        'redirect_uri' => McpFixtures::REDIRECT,
        'response_type' => 'code',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    // ⚠️ Refused BEFORE the person approves. Letting the authorization finish
    // and failing the first tool call instead is what happened once: the editor
    // told the person to check credentials that were perfect, and nothing was
    // written anywhere that said why.
    $this->getJson("/api/mcp/authorize?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'feature_not_in_plan');

    $this->postJson('/api/mcp/authorize', [
        'client_id' => $client->client_id,
        'redirect_uri' => McpFixtures::REDIRECT,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'approve' => true,
    ])->assertStatus(422);

    expect(App\Models\McpConnection::count())->toBe(0);
});

it('lets the consent screen through when the plan includes MCP', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $query = http_build_query([
        'client_id' => $client->client_id,
        'redirect_uri' => McpFixtures::REDIRECT,
        'response_type' => 'code',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    $this->getJson("/api/mcp/authorize?{$query}")->assertOk();
});

it('turns a valid token away when the plan does not include MCP', function () {
    // The exact production shape: the authorization completed, a connection was
    // stored, tokens were issued — and the very first call came back 403, so
    // `last_used_at` never moved off null. That null was the only evidence the
    // gate had fired, because nothing was logged.
    $user = McpFixtures::user(features: ['chat' => true, 'flow' => true]);
    [$connection, $token] = McpFixtures::connect($user);

    McpFixtures::call($this, $token, 'tools/list')
        ->assertStatus(403)
        ->assertJsonPath('code', 'feature_not_in_plan');

    expect($connection->fresh()->last_used_at)->toBeNull();
});

it('serves a token normally once the plan includes MCP', function () {
    $user = McpFixtures::user();
    [$connection, $token] = McpFixtures::connect($user);

    McpFixtures::call($this, $token, 'tools/list')->assertOk();

    expect($connection->fresh()->last_used_at)->not->toBeNull();
});
