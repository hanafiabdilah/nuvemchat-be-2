<?php

use App\Enums\Flow\NodeType;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\ActionNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function flowSaveUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('flow-saver-' . $tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate('flows.update', 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

/** start → one node of the type under test. */
function flowSavePayload(Flow $flow, string $type, mixed $data): array
{
    $start = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Start)->first()
        ?? $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    return [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'node-1', 'type' => $type, 'data' => $data, 'position_x' => 200, 'position_y' => 100],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-1', 'condition_value' => null],
        ],
    ];
}

test('an action node saves before its author has picked an action', function () {
    // The state every action node is in for the seconds between landing on the
    // canvas and being configured — and auto-save fires inside that gap, so a
    // rule that rejected it would make the node unusable rather than strict.
    $user = flowSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Router']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", flowSavePayload($flow, 'action', ['type' => null, 'parameters' => []]))
        ->assertOk();

    $node = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Action)->first();
    expect($node->data['type'])->toBeNull();
});

test('a configured assign_agent node round-trips', function () {
    $user = flowSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Router']);
    $agent = User::factory()->create(['tenant_id' => $user->tenant_id]);

    $payload = flowSavePayload($flow, 'action', [
        'type' => ActionNodes::ASSIGN_AGENT,
        'parameters' => [
            'agent_id' => $agent->id,
            'when_unavailable' => ActionNodes::UNAVAILABLE_ASSIGN_ANYWAY,
        ],
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", $payload)
        ->assertOk();

    $node = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Action)->first();

    expect($node->data['type'])->toBe(ActionNodes::ASSIGN_AGENT)
        ->and($node->data['parameters']['agent_id'])->toBe($agent->id)
        ->and($node->data['parameters']['when_unavailable'])->toBe(ActionNodes::UNAVAILABLE_ASSIGN_ANYWAY);
});

test('a flow cannot name an agent from another tenant', function () {
    $user = flowSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Router']);

    $stranger = flowSaveUser(); // their own tenant

    $payload = flowSavePayload($flow, 'action', [
        'type' => ActionNodes::ASSIGN_AGENT,
        'parameters' => ['agent_id' => $stranger->id],
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", $payload)
        ->assertStatus(422);
});

test('an action outside the published vocabulary is refused', function () {
    $user = flowSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Router']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", flowSavePayload($flow, 'action', [
            'type' => 'delete_everything',
            'parameters' => [],
        ]))
        ->assertStatus(422);
});

test('a status node saves as resolved and refuses anything else', function () {
    $user = flowSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Closer']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", flowSavePayload($flow, 'status', ['value' => 'resolved']))
        ->assertOk();

    expect(FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Status)->first()->data['value'])
        ->toBe('resolved');

    // 'open' is the placeholder the disabled builder used to ship, and it is not
    // a status this product has.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", flowSavePayload($flow, 'status', ['value' => 'open']))
        ->assertStatus(422);
});
