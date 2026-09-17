<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// The message nodes in the executor fixture must not reach the real Cloud API.
beforeEach(fn () => Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]));

function oneOutputUser(string $permission = 'flows.update'): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('one-output-'.$tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

/** A node as the builder sends it: its own id, since a new node has no database one. */
function oneOutputNode(string $id, string $type, mixed $data, int $x = 0): array
{
    return ['id' => $id, 'type' => $type, 'data' => $data, 'position_x' => $x, 'position_y' => 0];
}

function oneOutputText(string $body): array
{
    return ['body' => $body, 'message_type' => 'text'];
}

test('saving drops a second edge leaving the start node and reports what went', function () {
    // The shape a flow built before May 2026 can still be carrying: the canvas
    // draws both edges, and the engine has only ever walked one of them.
    $user = oneOutputUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Greeter']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", [
        'nodes' => [
            oneOutputNode((string) $start->id, 'start', null),
            oneOutputNode('node-live', 'message', oneOutputText('Taken'), 200),
            oneOutputNode('node-dead', 'message', oneOutputText('Never'), 200),
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-live', 'condition_value' => null],
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-dead', 'condition_value' => null],
        ],
    ]);

    $response->assertOk();

    $edges = FlowEdge::where('source_node_id', $start->id)->get();
    expect($edges)->toHaveCount(1);

    // The first one survives: it is the edge the executor was already taking,
    // so the cleanup changes what is stored without changing what runs.
    $kept = $flow->nodes()->find($edges->first()->target_node_id);
    expect($kept->data['body'])->toBe('Taken');

    // Named back in the ids the builder sent, which are the only ones it can
    // match against its own canvas.
    $response->assertJsonCount(1, 'dropped_edges')
        ->assertJsonPath('dropped_edges.0.source_node_id', (string) $start->id)
        ->assertJsonPath('dropped_edges.0.target_node_id', 'node-dead')
        ->assertJsonPath('dropped_edges.0.condition_value', null);
});

test('a save with one edge per output reports nothing dropped', function () {
    $user = oneOutputUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Greeter']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", [
        'nodes' => [
            oneOutputNode((string) $start->id, 'start', null),
            oneOutputNode('node-1', 'message', oneOutputText('Hi'), 200),
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-1', 'condition_value' => null],
        ],
    ])->assertOk()->assertJsonPath('dropped_edges', []);
});

test('the branches of a condition node are outputs of their own, not duplicates', function () {
    $user = oneOutputUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Router']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $condition = ['field' => 'contact.name', 'operator' => 'is_not_empty', 'value' => null];

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", [
        'nodes' => [
            oneOutputNode((string) $start->id, 'start', null),
            oneOutputNode('node-if', 'condition', $condition, 200),
            oneOutputNode('node-yes', 'message', oneOutputText('Yes'), 400),
            oneOutputNode('node-no', 'message', oneOutputText('No'), 400),
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-if', 'condition_value' => null],
            ['source_node_id' => 'node-if', 'target_node_id' => 'node-yes', 'condition_value' => 'true'],
            ['source_node_id' => 'node-if', 'target_node_id' => 'node-no', 'condition_value' => 'false'],
        ],
    ])->assertOk()->assertJsonPath('dropped_edges', []);

    $if = $flow->nodes()->where('type', NodeType::Condition)->first();
    expect(FlowEdge::where('source_node_id', $if->id)->count())->toBe(2);
});

test('saving drops a second edge on the same branch', function () {
    $user = oneOutputUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Router']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $condition = ['field' => 'contact.name', 'operator' => 'is_not_empty', 'value' => null];

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", [
        'nodes' => [
            oneOutputNode((string) $start->id, 'start', null),
            oneOutputNode('node-if', 'condition', $condition, 200),
            oneOutputNode('node-yes', 'message', oneOutputText('Yes'), 400),
            oneOutputNode('node-also', 'message', oneOutputText('Also yes'), 400),
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-if', 'condition_value' => null],
            ['source_node_id' => 'node-if', 'target_node_id' => 'node-yes', 'condition_value' => 'true'],
            ['source_node_id' => 'node-if', 'target_node_id' => 'node-also', 'condition_value' => 'true'],
        ],
    ])->assertOk()
        ->assertJsonCount(1, 'dropped_edges')
        ->assertJsonPath('dropped_edges.0.condition_value', 'true');

    $if = $flow->nodes()->where('type', NodeType::Condition)->first();
    expect(FlowEdge::where('source_node_id', $if->id)->count())->toBe(1);
});

test('the assistant is refused a duplicated output instead of having it dropped', function () {
    // The one caller that can be asked to try again: the model is told which
    // output, repairs it, and the person never sees the broken version.
    $nodes = [
        ['key' => 'start', 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
        ['key' => 'hello', 'type' => 'message', 'data' => oneOutputText('Hi'), 'position_x' => 300, 'position_y' => 0],
        ['key' => 'other', 'type' => 'message', 'data' => oneOutputText('Also hi'), 'position_x' => 300, 'position_y' => 200],
    ];

    $problems = FlowBlueprint::structureProblems($nodes, [
        ['source_key' => 'start', 'target_key' => 'hello', 'condition_value' => null],
        ['source_key' => 'start', 'target_key' => 'other', 'condition_value' => null],
    ]);

    expect($problems)->toContain('2 edges leave node "start"; an output leads to exactly one node, and the flow would only ever follow one of them.');
});

test('a duplicated branch is reported as that branch', function () {
    $nodes = [
        ['key' => 'start', 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
        ['key' => 'ask', 'type' => 'condition', 'data' => ['field' => 'contact.name', 'operator' => 'is_not_empty', 'value' => null], 'position_x' => 300, 'position_y' => 0],
        ['key' => 'yes', 'type' => 'message', 'data' => oneOutputText('Yes'), 'position_x' => 600, 'position_y' => 0],
        ['key' => 'no', 'type' => 'message', 'data' => oneOutputText('No'), 'position_x' => 600, 'position_y' => 200],
    ];

    $problems = FlowBlueprint::structureProblems($nodes, [
        ['source_key' => 'start', 'target_key' => 'ask', 'condition_value' => null],
        ['source_key' => 'ask', 'target_key' => 'yes', 'condition_value' => 'true'],
        ['source_key' => 'ask', 'target_key' => 'no', 'condition_value' => 'true'],
    ]);

    expect($problems)->toContain('2 edges leave the "true" branch of node "ask"; an output leads to exactly one node, and the flow would only ever follow one of them.');
});

test('one edge per branch raises no duplicate-output problem', function () {
    $nodes = [
        ['key' => 'start', 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
        ['key' => 'ask', 'type' => 'condition', 'data' => ['field' => 'contact.name', 'operator' => 'is_not_empty', 'value' => null], 'position_x' => 300, 'position_y' => 0],
        ['key' => 'yes', 'type' => 'message', 'data' => oneOutputText('Yes'), 'position_x' => 600, 'position_y' => 0],
        ['key' => 'no', 'type' => 'message', 'data' => oneOutputText('No'), 'position_x' => 600, 'position_y' => 200],
    ];

    $problems = FlowBlueprint::structureProblems($nodes, [
        ['source_key' => 'start', 'target_key' => 'ask', 'condition_value' => null],
        ['source_key' => 'ask', 'target_key' => 'yes', 'condition_value' => 'true'],
        ['source_key' => 'ask', 'target_key' => 'no', 'condition_value' => 'false'],
    ]);

    expect($problems)->toBe([]);
});

test('an export carrying a duplicate still imports, minus the dead edge', function () {
    // Export is a straight dump of the database, so a flow that has carried a
    // duplicate since before the guard exports with one. Refusing the file
    // would mean a workspace cannot move its own flow.
    $user = oneOutputUser('flows.create');

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/flows/import', [
        'format' => 'nuvemchat.flow',
        'version' => 1,
        'flow' => [
            'name' => 'Legacy',
            'nodes' => [
                ['key' => 'n1', 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
                ['key' => 'n2', 'type' => 'message', 'data' => oneOutputText('Taken'), 'position_x' => 300, 'position_y' => 0],
                ['key' => 'n3', 'type' => 'message', 'data' => oneOutputText('Never'), 'position_x' => 300, 'position_y' => 200],
            ],
            'edges' => [
                ['source_key' => 'n1', 'target_key' => 'n2', 'condition_value' => null],
                ['source_key' => 'n1', 'target_key' => 'n3', 'condition_value' => null],
            ],
        ],
    ]);

    $response->assertCreated()->assertJsonCount(1, 'dropped_edges');

    $flow = Flow::where('name', 'Legacy')->firstOrFail();
    $start = $flow->nodes()->where('type', NodeType::Start)->first();
    $edges = FlowEdge::where('source_node_id', $start->id)->get();

    expect($edges)->toHaveCount(1)
        ->and($flow->nodes()->find($edges->first()->target_node_id)->data['body'])->toBe('Taken');
});

test('a flow that still carries two edges on one output follows the first', function () {
    // Pins the contract rather than the storage engine: MySQL happens to hand
    // back the lowest id today, and this is what says it must. Deliberately
    // wired so edge order and node order disagree — the edge created first
    // points at the node created second.
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Legacy']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $second = $flow->nodes()->create(['type' => NodeType::Message, 'data' => oneOutputText('Second node'), 'position_x' => 200, 'position_y' => 0]);
    $first = $flow->nodes()->create(['type' => NodeType::Message, 'data' => oneOutputText('First edge'), 'position_x' => 200, 'position_y' => 200]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $first->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $second->id, 'condition_value' => null]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);

    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'username' => '5511999999999',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Pending,
    ]);

    (new FlowExecutor)->startFlow($conversation);

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Text)
        ->pluck('body')
        ->all();

    expect($sent)->toContain('First edge')
        ->and($sent)->not->toContain('Second node');
});
