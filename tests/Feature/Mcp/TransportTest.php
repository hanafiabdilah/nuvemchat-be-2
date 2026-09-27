<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\McpFixtures;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['mcp.enabled' => true]));

it('answers server/discover with the versions it speaks', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $result = McpFixtures::call($this, $token, 'server/discover')->assertOk()->json('result');

    expect($result['supportedVersions'])->toContain('2026-07-28')
        ->and($result['resultType'])->toBe('complete')
        ->and($result['_meta']['io.modelcontextprotocol/serverInfo']['name'])->toBe('pingly')
        // The instructions are read before anything else happens, and they are
        // where "call get_flow_specification first" has to be said.
        ->and($result['instructions'])->toContain('get_flow_specification');
});

it('still serves a client that opens with the old handshake', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    // No `_meta`, an `initialize` call: Claude Desktop and several other
    // clients are still here. A server that answered only the new revision
    // would fail them with a protocol error they cannot act on.
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Old', 'version' => '1']],
    ], ['Authorization' => 'Bearer '.$token, 'MCP-Protocol-Version' => '2025-06-18']);

    $response->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.serverInfo.name', 'pingly');

    // And no resultType: that field belongs to the new revision, and an old
    // client validating the result would not expect it.
    expect($response->json('result'))->not->toHaveKey('resultType');

    // The handshake's follow-up notification is acknowledged, not answered.
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
    ], ['Authorization' => 'Bearer '.$token, 'MCP-Protocol-Version' => '2025-06-18'])->assertStatus(202);

    // And it can then work, on the legacy envelope.
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) [],
    ], ['Authorization' => 'Bearer '.$token, 'MCP-Protocol-Version' => '2025-06-18'])
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list_flows');
});

it('refuses a version it does not speak, and says which it does', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2099-01-01']],
    ], ['Authorization' => 'Bearer '.$token, 'MCP-Protocol-Version' => '2099-01-01', 'Mcp-Method' => 'tools/list']);

    $response->assertStatus(400)
        ->assertJsonPath('error.code', -32022)
        ->assertJsonPath('error.data.requested', '2099-01-01');

    expect($response->json('error.data.supported'))->toContain('2026-07-28');
});

it('refuses a request whose headers disagree with its body', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    // A proxy routing on the header and this server acting on the body would
    // otherwise be looking at two different requests.
    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'delete_flow',
            'arguments' => [],
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
        ],
    ], [
        'Authorization' => 'Bearer '.$token,
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => 'list_flows',
    ])->assertStatus(400)->assertJsonPath('error.code', -32020);
});

it('answers an unknown method with 404 and a JSON-RPC error', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    // The JSON-RPC body is what distinguishes this from the 404 an old
    // HTTP+SSE server gives for not hosting a modern endpoint at all.
    McpFixtures::call($this, $token, 'resources/list')
        ->assertStatus(404)
        ->assertJsonPath('error.code', -32601);
});

it('turns down a request that arrives from a browser page', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) [],
    ], ['Authorization' => 'Bearer '.$token, 'Origin' => 'https://somebody-elses-site.example'])
        ->assertStatus(403);
});
