<?php

use App\Models\McpConnection;
use App\Models\McpToken;
use App\Services\Mcp\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\McpFixtures;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['mcp.enabled' => true]));

/** The query a client sends to the consent screen. */
function authorizeParams(string $clientId, string $challenge, array $overrides = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'redirect_uri' => McpFixtures::REDIRECT,
        'response_type' => 'code',
        'scope' => Scopes::FLOWS_READ.' '.Scopes::FLOWS_WRITE,
        'state' => 'st4te',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'resource' => rtrim(url('/'), '/').'/mcp',
    ], $overrides);
}

it('registers a client without asking it for a credential', function () {
    $response = $this->postJson('/mcp/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://127.0.0.1:41234/callback'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('client_name', 'Claude Code')
        ->assertJsonPath('token_endpoint_auth_method', 'none');

    expect($response->json('client_id'))->toStartWith('mcpc_');
});

it('refuses a redirect that is neither https nor loopback', function () {
    // OAuth 2.1 allows plain http only on loopback, and a scheme like
    // `javascript:` is a way to run code wherever the browser lands.
    $this->postJson('/mcp/oauth/register', [
        'client_name' => 'Nope',
        'redirect_uris' => ['http://evil.example/callback'],
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');

    $this->postJson('/mcp/oauth/register', [
        'client_name' => 'Nope',
        'redirect_uris' => ['javascript:alert(1)'],
    ])->assertStatus(400);
});

it('shows the person what they are approving', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $this->getJson('/api/mcp/authorize?'.http_build_query(authorizeParams($client->client_id, $challenge)))
        ->assertOk()
        ->assertJsonPath('data.client.name', 'Claude Code')
        ->assertJsonPath('data.scopes', [Scopes::FLOWS_READ, Scopes::FLOWS_WRITE])
        ->assertJsonPath('data.account.name', $user->name);
});

it('will not send the person to an address the client never registered', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    // Reported on the screen, never through the redirect: the redirect is the
    // thing in question, so it cannot be used to report on itself.
    $this->getJson('/api/mcp/authorize?'.http_build_query(
        authorizeParams($client->client_id, $challenge, ['redirect_uri' => 'http://127.0.0.1:9/evil'])
    ))->assertStatus(422)->assertJsonPath('code', 'mcp_bad_redirect');
});

it('requires PKCE with S256', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $this->getJson('/api/mcp/authorize?'.http_build_query(
        authorizeParams($client->client_id, $challenge, ['code_challenge_method' => 'plain'])
    ))->assertStatus(422)->assertJsonPath('code', 'mcp_bad_challenge_method');
});

it('refuses a token meant for a different server', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $this->getJson('/api/mcp/authorize?'.http_build_query(
        authorizeParams($client->client_id, $challenge, ['resource' => 'https://someone-else.example/mcp'])
    ))->assertStatus(422)->assertJsonPath('code', 'mcp_bad_resource');
});

it('carries state and iss back so the client can tell the answer came from us', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $redirect = $this->postJson('/api/mcp/authorize', authorizeParams($client->client_id, $challenge) + ['approve' => true])
        ->assertOk()
        ->json('data.redirect_to');

    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    expect($query['state'])->toBe('st4te')
        ->and($query['iss'])->toBe(rtrim(url('/'), '/'))
        ->and($query['code'])->not->toBeEmpty();
});

it('sends back an error, not a code, when the person says no', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $redirect = $this->postJson('/api/mcp/authorize', authorizeParams($client->client_id, $challenge) + ['approve' => false])
        ->assertOk()
        ->json('data.redirect_to');

    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    expect($query['error'])->toBe('access_denied')
        ->and($query)->not->toHaveKey('code');

    expect(McpConnection::count())->toBe(0);
});

it('exchanges a code for tokens and opens one connection', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [$verifier, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $redirect = $this->postJson('/api/mcp/authorize', authorizeParams($client->client_id, $challenge) + ['approve' => true])
        ->json('data.redirect_to');

    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    $tokens = $this->postJson('/mcp/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->client_id,
        'code' => $query['code'],
        'code_verifier' => $verifier,
        'redirect_uri' => McpFixtures::REDIRECT,
        'resource' => rtrim(url('/'), '/').'/mcp',
    ])->assertOk()->json();

    expect($tokens['token_type'])->toBe('Bearer')
        ->and($tokens['scope'])->toBe(Scopes::FLOWS_READ.' '.Scopes::FLOWS_WRITE)
        ->and($tokens['access_token'])->toStartWith('mcp_at_')
        ->and($tokens['refresh_token'])->toStartWith('mcp_rt_');

    $connection = McpConnection::sole();
    expect($connection->user_id)->toBe($user->id)
        ->and($connection->tenant_id)->toBe($user->tenant_id);

    // And the token works.
    McpFixtures::call($this, $tokens['access_token'], 'tools/list')->assertOk();
});

it('refuses a code redeemed with the wrong verifier', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $redirect = $this->postJson('/api/mcp/authorize', authorizeParams($client->client_id, $challenge) + ['approve' => true])
        ->json('data.redirect_to');
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    $this->postJson('/mcp/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->client_id,
        'code' => $query['code'],
        'code_verifier' => str_repeat('b', 43),
        'redirect_uri' => McpFixtures::REDIRECT,
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(McpConnection::count())->toBe(0);
});

it('treats a code used twice as a theft and takes down what it produced', function () {
    $user = McpFixtures::user();
    $client = McpFixtures::client();
    [$verifier, $challenge] = McpFixtures::pkce();

    Sanctum::actingAs($user);

    $redirect = $this->postJson('/api/mcp/authorize', authorizeParams($client->client_id, $challenge) + ['approve' => true])
        ->json('data.redirect_to');
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    $exchange = fn () => $this->postJson('/mcp/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->client_id,
        'code' => $query['code'],
        'code_verifier' => $verifier,
        'redirect_uri' => McpFixtures::REDIRECT,
    ]);

    $first = $exchange()->assertOk()->json();

    $exchange()->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // The legitimate client loses a session it can rebuild in one redirect; a
    // thief loses the tokens. That trade is the whole point.
    expect(McpConnection::sole()->revoked_at)->not->toBeNull();

    McpFixtures::call($this, $first['access_token'], 'tools/list')->assertStatus(401);
});

it('rotates the refresh token, and a reused one ends the connection', function () {
    $user = McpFixtures::user();
    [$connection] = McpFixtures::connect($user);

    $first = McpToken::mint(McpToken::TYPE_REFRESH);
    McpToken::create([
        'token_hash' => McpToken::hash($first),
        'mcp_connection_id' => $connection->id,
        'type' => McpToken::TYPE_REFRESH,
        'expires_at' => now()->addDays(30),
    ]);

    $body = [
        'grant_type' => 'refresh_token',
        'client_id' => $connection->client->client_id,
        'refresh_token' => $first,
    ];

    $refreshed = $this->postJson('/mcp/oauth/token', $body)->assertOk()->json();

    expect($refreshed['refresh_token'])->not->toBe($first);

    // Second use of a rotated token: one of the two holders is not the client.
    $this->postJson('/mcp/oauth/token', $body)->assertStatus(400);

    expect($connection->fresh()->revoked_at)->not->toBeNull();
});

it('lists and disconnects from the dashboard', function () {
    $user = McpFixtures::user();
    [$connection, $token] = McpFixtures::connect($user);

    Sanctum::actingAs($user);

    $this->getJson('/api/mcp/connections')
        ->assertOk()
        ->assertJsonPath('data.0.client_name', 'Claude Code')
        ->assertJsonPath('data.0.is_mine', true)
        // Nothing here is a credential: no token, no client_id.
        ->assertJsonMissingPath('data.0.token');

    $this->deleteJson("/api/mcp/connections/{$connection->id}")->assertOk();

    McpFixtures::call($this, $token, 'tools/list')->assertStatus(401);
});

it('does not show one agent another agent\'s connection', function () {
    $mine = McpFixtures::user();
    $theirs = McpFixtures::user();

    McpFixtures::connect($theirs);

    Sanctum::actingAs($mine);

    $this->getJson('/api/mcp/connections')->assertOk()->assertJsonCount(0, 'data');
});
