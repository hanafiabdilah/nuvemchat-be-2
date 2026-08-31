<?php

use App\Enums\Flow\NodeType;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\MessageNodes;
use App\Services\Flow\ResponseNodes;
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
            'wait_for_reply' => false,
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
            'wait_for_reply' => true,
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
            'wait_for_reply' => false,
            'messages' => $tooMany,
        ]))
        ->assertStatus(422);
});

test('a response node saves its no-reply limit and both branch edges', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Ask']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $payload = [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            [
                'id' => 'node-ask',
                'type' => 'response',
                'data' => [
                    'body' => 'Qual é o seu nome?',
                    'message_type' => 'text',
                    'variable_key' => 'nome',
                    'validation' => 'any',
                    'timeout_seconds' => 300,
                ],
                'position_x' => 200,
                'position_y' => 0,
            ],
            ['id' => 'node-ok', 'type' => 'message', 'data' => ['messages' => [['message_type' => 'text', 'body' => 'Obrigado!']]], 'position_x' => 400, 'position_y' => 0],
            ['id' => 'node-quiet', 'type' => 'message', 'data' => ['messages' => [['message_type' => 'text', 'body' => 'Ainda está aí?']]], 'position_x' => 400, 'position_y' => 200],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-ask', 'condition_value' => null],
            ['source_node_id' => 'node-ask', 'target_node_id' => 'node-ok', 'condition_value' => ResponseNodes::BRANCH_REPLIED],
            ['source_node_id' => 'node-ask', 'target_node_id' => 'node-quiet', 'condition_value' => ResponseNodes::BRANCH_TIMEOUT],
        ],
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", $payload)
        ->assertOk();

    $ask = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Response)->first();

    expect(ResponseNodes::timeoutSeconds($ask->data))->toBe(300)
        ->and(FlowEdge::where('source_node_id', $ask->id)->pluck('condition_value')->sort()->values()->all())
        ->toBe([ResponseNodes::BRANCH_REPLIED, ResponseNodes::BRANCH_TIMEOUT]);
});

test('a response node refuses a limit past a day', function () {
    $user = messageSaveUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Ask']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", messageSavePayload($flow, 'response', [
            'body' => 'Qual é o seu nome?',
            'message_type' => 'text',
            'variable_key' => 'nome',
            'timeout_seconds' => ResponseNodes::MAX_TIMEOUT_SECONDS + 1,
        ]))
        ->assertStatus(422);
});
