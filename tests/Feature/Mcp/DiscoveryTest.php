<?php

use App\Services\Mcp\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\McpFixtures;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['mcp.enabled' => true]));

it('is invisible until the platform switches it on', function () {
    config(['mcp.enabled' => false]);

    $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
    $this->postJson('/mcp', [])->assertNotFound();
});

it('tells a client which authorization server to use', function () {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('resource', rtrim(url('/'), '/').'/mcp')
        ->assertJsonPath('authorization_servers.0', rtrim(url('/'), '/'))
        ->assertJsonPath('scopes_supported', Scopes::all());
});

it('serves the resource document under the endpoint path too', function () {
    // The first of the two places the specification tells a client to look.
    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('resource', rtrim(url('/'), '/').'/mcp');
});

it('advertises only the parts of OAuth it actually implements', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', rtrim(url('/'), '/'))
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
        // No `plain`: OAuth 2.1 removed it, and offering it would advertise a
        // downgrade PKCE exists to prevent.
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('token_endpoint_auth_methods_supported', ['none'])
        ->assertJsonPath('authorization_response_iss_parameter_supported', true);
});

it('challenges an unauthenticated call with the address of its own metadata', function () {
    $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    $response->assertStatus(401);

    // Without resource_metadata here a client that was handed only the endpoint
    // URL has no way to discover where to authorize.
    expect($response->headers->get('WWW-Authenticate'))
        ->toContain('resource_metadata="'.rtrim(url('/'), '/').'/.well-known/oauth-protected-resource"')
        ->toContain('Bearer');
});

it('refuses the methods the current revision removed', function () {
    $this->get('/mcp')->assertStatus(405);
    $this->delete('/mcp')->assertStatus(405);
});

it('does not accept an expired token', function () {
    $user = McpFixtures::user();
    [$connection, $token] = McpFixtures::connect($user);

    $connection->tokens()->update(['expires_at' => now()->subMinute()]);

    McpFixtures::call($this, $token, 'tools/list')->assertStatus(401);
});

it('stops working the moment the connection is revoked', function () {
    $user = McpFixtures::user();
    [$connection, $token] = McpFixtures::connect($user);

    McpFixtures::call($this, $token, 'tools/list')->assertOk();

    $connection->revoke();

    McpFixtures::call($this, $token, 'tools/list')->assertStatus(401);
});
