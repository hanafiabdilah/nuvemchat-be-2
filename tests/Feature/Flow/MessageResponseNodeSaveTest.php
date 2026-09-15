<?php

use App\Enums\Flow\NodeType;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\MessageNodes;
use App\Services\Flow\WaitResponseNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function messageSaveUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('message-saver-' . $tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate('flows.update', 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

/** @param  array<int, array<string, mixed>>  $extraNodes */
function messageSavePayload(Flow $flow, string $type, mixed $data, array $edges = []): array
{
    $start = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Start)->first()
        ?? $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    return [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'node-1', 'type' => $type, 'data' => $data, 'position_x' => 200, 'position_y' => 100],
        ],
        'edges' => array_merge(
            [['source_node_id' => (string) $start->id, 'target_node_id' => 'node-1', 'condition_value' => null]],
            $edges
        ),
    ];
}

test('a message node saves its list of bubbles', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Greeter']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", messageSavePayload($flow, 'message', [
            'messages' => [
                ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 0],
                ['message_type' => 'text', 'body' => 'Tudo bem?', 'delay' => 4],
            ],
        ]))
        ->assertOk();

    $node = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Message)->first();

    expect(MessageNodes::items($node->data))->toHaveCount(2)
        ->and($node->data['messages'][1]['delay'])->toBe(4);
});

test('a message node saves while its first bubble is still empty', function () {
    // The state every message node is in the moment it lands on the canvas, and
    // auto-save fires inside that gap. Rejecting it would make the node
    // unusable rather than strict — the executor skips empty bubbles instead.
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Greeter']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", messageSavePayload($flow, 'message', [
            'messages' => [['message_type' => 'text', 'body' => '']],
        ]))
        ->assertOk();
});

test('a message node refuses more bubbles than one node may hold', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Greeter']);

    $tooMany = array_fill(0, MessageNodes::MAX_ITEMS + 1, ['message_type' => 'text', 'body' => 'oi']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", messageSavePayload($flow, 'message', [
            'messages' => $tooMany,
        ]))
        ->assertStatus(422);
});

test('a wait-for-reply node saves with nothing set — the plain pause', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Pause']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", messageSavePayload($flow, 'wait_response', WaitResponseNodes::defaults()))
        ->assertOk();

    expect(FlowNode::where('flow_id', $flow->id)->where('type', NodeType::WaitResponse)->exists())->toBeTrue();
});

test('a wait-for-reply node saves its limit, buffer and both branch edges', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Ask']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $payload = [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            [
                'id' => 'node-wait',
                'type' => 'wait_response',
                'data' => [
                    'message' => 'Qual é o seu e-mail?',
                    'variable_key' => 'email',
                    'timeout_seconds' => 3 * 86400,
                    'timeout_unit' => 'days',
                    'buffer_seconds' => 20,
                    'validation' => 'email',
                    'error_message' => 'Não consegui ler esse e-mail.',
                ],
                'position_x' => 200,
                'position_y' => 0,
            ],
            ['id' => 'node-ok', 'type' => 'message', 'data' => ['messages' => [['message_type' => 'text', 'body' => 'Obrigado!']]], 'position_x' => 400, 'position_y' => 0],
            ['id' => 'node-quiet', 'type' => 'message', 'data' => ['messages' => [['message_type' => 'text', 'body' => 'Ainda está aí?']]], 'position_x' => 400, 'position_y' => 200],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-wait', 'condition_value' => null],
            ['source_node_id' => 'node-wait', 'target_node_id' => 'node-ok', 'condition_value' => WaitResponseNodes::BRANCH_REPLIED],
            ['source_node_id' => 'node-wait', 'target_node_id' => 'node-quiet', 'condition_value' => WaitResponseNodes::BRANCH_TIMEOUT],
        ],
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", $payload)
        ->assertOk();

    $wait = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::WaitResponse)->first();

    expect(WaitResponseNodes::timeoutSeconds($wait->data))->toBe(3 * 86400)
        ->and(WaitResponseNodes::bufferSeconds($wait->data))->toBe(20)
        ->and(FlowEdge::where('source_node_id', $wait->id)->pluck('condition_value')->sort()->values()->all())
        ->toBe([WaitResponseNodes::BRANCH_REPLIED, WaitResponseNodes::BRANCH_TIMEOUT]);
});

test('a wait-for-reply node refuses a limit past 31 days', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Ask']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", messageSavePayload($flow, 'wait_response', [
            'timeout_seconds' => WaitResponseNodes::MAX_TIMEOUT_SECONDS + 1,
        ]))
        ->assertStatus(422);
});

test('a wait-for-reply node refuses a buffer longer than typing', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Ask']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", messageSavePayload($flow, 'wait_response', [
            'buffer_seconds' => WaitResponseNodes::MAX_BUFFER_SECONDS + 1,
        ]))
        ->assertStatus(422);
});
