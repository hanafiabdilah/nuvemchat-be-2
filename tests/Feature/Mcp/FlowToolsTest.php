<?php

use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Tag;
use App\Services\Mcp\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\McpFixtures;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['mcp.enabled' => true]));

function mcpBlueprint(): array
{
    return [
        'nodes' => [
            ['key' => '1', 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['key' => '2', 'type' => 'message', 'data' => [
                'messages' => [['message_type' => 'text', 'body' => 'Olá!', 'delay' => 0]],
            ], 'position_x' => 280, 'position_y' => 0],
            ['key' => '3', 'type' => 'status', 'data' => ['value' => 'resolved'], 'position_x' => 560, 'position_y' => 0],
        ],
        'edges' => [
            ['source_key' => '1', 'target_key' => '2', 'condition_value' => null],
            ['source_key' => '2', 'target_key' => '3', 'condition_value' => null],
        ],
    ];
}

// ─────────────────────────── What a client sees ───────────────────────────

it('offers only the tools this connection and this person can use', function () {
    $user = McpFixtures::user(['flows.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::FLOWS_READ]);

    $names = collect(McpFixtures::call($this, $token, 'tools/list')->json('result.tools'))->pluck('name');

    expect($names)->toContain('list_flows', 'get_flow', 'get_flow_specification', 'validate_flow')
        // A model shown a tool it cannot use will call it and burn a turn on
        // an error nobody can act on.
        ->not->toContain('create_flow')
        ->not->toContain('update_flow')
        ->not->toContain('delete_flow');
});

it('hides a write tool from a person who lost the permission, even with the scope', function () {
    // The scope is a ceiling the person set once; the permission is what the
    // workspace decides continuously. Only the second one moved here.
    $user = McpFixtures::user(['flows.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::FLOWS_READ, Scopes::FLOWS_WRITE]);

    $names = collect(McpFixtures::call($this, $token, 'tools/list')->json('result.tools'))->pluck('name');

    expect($names)->not->toContain('update_flow');
});

it('explains why, when a client calls a tool it may not use', function () {
    $user = McpFixtures::user(['flows.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::FLOWS_READ]);

    $result = McpFixtures::tool($this, $token, 'update_flow', ['flow_id' => 1, 'nodes' => []]);

    expect($result['isError'])->toBeTrue()
        // "Unknown tool" would send them looking in the wrong place.
        ->and($result['content'][0]['text'])->toContain(Scopes::FLOWS_WRITE);
});

// ───────────────────────────────── Reading ─────────────────────────────────

it('lists the workspace\'s flows without their contents', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Boas-vindas']);

    $result = McpFixtures::tool($this, $token, 'list_flows');

    expect($result['structuredContent']['flows'])->toHaveCount(1)
        ->and($result['structuredContent']['flows'][0]['name'])->toBe('Boas-vindas');
});

it('never shows another workspace a flow', function () {
    $mine = McpFixtures::user();
    $theirs = McpFixtures::user();
    [, $token] = McpFixtures::connect($mine);

    $other = Flow::create(['tenant_id' => $theirs->tenant_id, 'name' => 'Não é seu']);

    expect(McpFixtures::tool($this, $token, 'list_flows')['structuredContent']['flows'])->toBe([]);

    $result = McpFixtures::tool($this, $token, 'get_flow', ['flow_id' => $other->id]);

    expect($result['isError'])->toBeTrue();
});

it('serves the format and this workspace\'s real ids together', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'urgente', 'color' => '#ff0000']);

    $result = McpFixtures::tool($this, $token, 'get_flow_specification');

    // The format comes from the same constants the validator enforces, so the
    // model and the product can never disagree about what a flow is.
    expect($result['content'][0]['text'])->toContain('nuvemchat.flow');

    $vocabulary = $result['structuredContent']['vocabulary'];

    expect($vocabulary['tags'])->toBe([['id' => $tag->id, 'name' => 'urgente']])
        ->and($vocabulary)->toHaveKeys(['agents', 'ai_agents', 'payment_integrations', 'lead_stages', 'flows']);
});

// ───────────────────────────────── Writing ─────────────────────────────────

it('creates a flow and reads it back in the same shape', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $created = McpFixtures::tool($this, $token, 'create_flow', ['name' => 'Atendimento'] + mcpBlueprint());

    expect($created['isError'] ?? false)->toBeFalse();

    $id = $created['structuredContent']['flow_id'];

    $read = McpFixtures::tool($this, $token, 'get_flow', ['flow_id' => $id]);

    expect($read['structuredContent']['nodes'])->toHaveCount(3)
        ->and($read['structuredContent']['edges'])->toHaveCount(2)
        // Exactly one start node: the flow's own auto-created one must not be
        // left beside the caller's.
        ->and(collect($read['structuredContent']['nodes'])->where('type', 'start'))->toHaveCount(1);
});

it('refuses to create a flow that would not run, and writes nothing', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $blueprint = mcpBlueprint();
    // A node nothing leads to. It would save perfectly and then never run —
    // the failure that is invisible until a customer hits it.
    $blueprint['nodes'][] = ['key' => '9', 'type' => 'message', 'data' => [
        'messages' => [['message_type' => 'text', 'body' => 'Órfão', 'delay' => 0]],
    ], 'position_x' => 900, 'position_y' => 0];

    $result = McpFixtures::tool($this, $token, 'create_flow', ['name' => 'Quebrado'] + $blueprint);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('cannot be reached');

    expect(Flow::count())->toBe(0);
});

it('refuses a flow naming another workspace\'s tag', function () {
    $mine = McpFixtures::user();
    $theirs = McpFixtures::user();
    [, $token] = McpFixtures::connect($mine);

    $foreign = Tag::create(['tenant_id' => $theirs->tenant_id, 'name' => 'deles', 'color' => '#000000']);

    $blueprint = mcpBlueprint();
    $blueprint['nodes'][1] = ['key' => '2', 'type' => 'tagging', 'data' => [
        'action' => 'add', 'tags' => [$foreign->id],
    ], 'position_x' => 280, 'position_y' => 0];

    $result = McpFixtures::tool($this, $token, 'create_flow', ['name' => 'Roubo'] + $blueprint);

    expect($result['isError'])->toBeTrue()
        // Named by its key, not by its index in an array the model does not
        // think in.
        ->and($result['content'][0]['text'])->toContain('Node "2"');
});

it('lays out a graph whose nodes came with no positions', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $blueprint = mcpBlueprint();

    foreach ($blueprint['nodes'] as $i => $node) {
        unset($blueprint['nodes'][$i]['position_x'], $blueprint['nodes'][$i]['position_y']);
    }

    $created = McpFixtures::tool($this, $token, 'create_flow', ['name' => 'Sem posições'] + $blueprint);

    expect($created['isError'] ?? false)->toBeFalse();

    $positions = FlowNode::where('flow_id', $created['structuredContent']['flow_id'])
        ->pluck('position_x');

    // Two nodes on the same point read as a node that failed to appear.
    expect($positions->unique())->toHaveCount(3);
});

it('replaces a flow whole and hands back the new node ids', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $id = McpFixtures::tool($this, $token, 'create_flow', ['name' => 'V1'] + mcpBlueprint())['structuredContent']['flow_id'];

    $current = McpFixtures::tool($this, $token, 'get_flow', ['flow_id' => $id])['structuredContent'];

    // Drop the closing step, keeping the keys we were given.
    $nodes = collect($current['nodes'])->reject(fn ($n) => $n['type'] === 'status')->values()->all();
    $edges = collect($current['edges'])->filter(
        fn ($e) => collect($nodes)->pluck('key')->contains($e['target_key'])
    )->values()->all();

    $updated = McpFixtures::tool($this, $token, 'update_flow', [
        'flow_id' => $id, 'name' => 'V2', 'nodes' => $nodes, 'edges' => $edges,
    ]);

    expect($updated['isError'] ?? false)->toBeFalse()
        ->and($updated['structuredContent']['name'])->toBe('V2')
        // Without this map a caller re-sends its own keys next time, creating
        // the nodes again and deleting the rows a live conversation stands on.
        ->and($updated['structuredContent']['node_keys'])->toHaveCount(2);

    expect(FlowNode::where('flow_id', $id)->count())->toBe(2);
});

it('saves a flow that was already broken, and says what is wrong', function () {
    // create_flow is strict because every problem is one it just made;
    // update_flow is not, because a flow may have carried a dead branch since
    // long before an editor was involved — and refusing would mean older flows
    // cannot be touched at all.
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $id = McpFixtures::tool($this, $token, 'create_flow', ['name' => 'V1'] + mcpBlueprint())['structuredContent']['flow_id'];

    $current = McpFixtures::tool($this, $token, 'get_flow', ['flow_id' => $id])['structuredContent'];
    $current['nodes'][] = ['key' => 'orphan', 'type' => 'message', 'data' => [
        'messages' => [['message_type' => 'text', 'body' => 'Sozinho', 'delay' => 0]],
    ], 'position_x' => 1200, 'position_y' => 0];

    $updated = McpFixtures::tool($this, $token, 'update_flow', [
        'flow_id' => $id, 'nodes' => $current['nodes'], 'edges' => $current['edges'],
    ]);

    expect($updated['isError'] ?? false)->toBeFalse()
        ->and($updated['structuredContent']['warnings'])->not->toBeEmpty();

    expect(FlowNode::where('flow_id', $id)->count())->toBe(4);
});

it('checks a flow without writing it', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $sound = McpFixtures::tool($this, $token, 'validate_flow', mcpBlueprint());

    expect($sound['structuredContent']['valid'])->toBeTrue();

    $broken = mcpBlueprint();
    $broken['edges'][] = ['source_key' => '1', 'target_key' => '3', 'condition_value' => 'nope'];

    $result = McpFixtures::tool($this, $token, 'validate_flow', $broken);

    expect($result['structuredContent']['valid'])->toBeFalse()
        ->and($result['structuredContent']['problems'])->not->toBeEmpty();

    expect(Flow::count())->toBe(0);
});

it('will not delete a flow a channel is still running', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Em uso']);

    \App\Models\Connection::create([
        'tenant_id' => $user->tenant_id,
        'name' => 'WhatsApp principal',
        'channel' => \App\Enums\Connection\Channel::Telegram,
        'flow_id' => $flow->id,
    ]);

    $result = McpFixtures::tool($this, $token, 'delete_flow', ['flow_id' => $flow->id]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('WhatsApp principal');

    expect(Flow::whereKey($flow->id)->exists())->toBeTrue();
});

it('deletes a flow nothing is running', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Rascunho']);

    $result = McpFixtures::tool($this, $token, 'delete_flow', ['flow_id' => $flow->id]);

    expect($result['isError'] ?? false)->toBeFalse();
    expect(Flow::whereKey($flow->id)->exists())->toBeFalse();
});

it('records every write in the platform audit trail', function () {
    $user = McpFixtures::user();
    [, $token] = McpFixtures::connect($user);

    McpFixtures::tool($this, $token, 'create_flow', ['name' => 'Auditado'] + mcpBlueprint());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'mcp.flow.created',
        'actor_id' => $user->id,
        'actor_type' => \App\Models\User::class,
    ]);
});
