<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Enums\Flow\NodeType;
use App\Models\Connection;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\InteractiveNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function interactiveTestUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('flow-editor-' . $tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate('flows.update', 'web'));
    $role->givePermissionTo(Permission::findOrCreate('connections.update', 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

function interactiveConnection(User $user, Channel $channel, ?Flow $flow = null): Connection
{
    return Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => $channel,
        'name' => $channel->value . ' ' . uniqid(),
        'color' => '#22c55e',
        'status' => Status::Active,
        'flow_id' => $flow?->id,
    ]);
}

/** A save payload with one start node and one interactive (button) node. */
function interactiveSavePayload(Flow $flow): array
{
    $start = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Start)->first()
        ?? $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    return [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            [
                'id' => 'node-1',
                'type' => 'interactive',
                'data' => [
                    'interactive_type' => 'button',
                    'header' => '',
                    'body' => 'Pick one',
                    'footer' => '',
                    'buttons' => [
                        ['id' => 'btn_a1', 'title' => 'Yes'],
                        ['id' => 'btn_b2', 'title' => 'No'],
                    ],
                    'button_label' => '',
                    'sections' => [],
                ],
                'position_x' => 200,
                'position_y' => 100,
            ],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-1', 'condition_value' => null],
        ],
    ];
}

test('a flow bound only to whatsapp official accepts an interactive node', function () {
    $user = interactiveTestUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Menu']);
    interactiveConnection($user, Channel::WhatsappOfficial, $flow);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", interactiveSavePayload($flow))
        ->assertOk();

    expect(FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Interactive)->exists())->toBeTrue();
});

// The node used to be fenced off to WhatsApp Official from both ends, because
// only the Cloud API draws tappable buttons. It is a menu, though, and every
// channel can put a menu in front of somebody — so the fence is gone and only
// the rendering changes. These two guard the fence staying gone.
test('a flow bound to another channel accepts an interactive node', function () {
    $user = interactiveTestUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Menu']);
    interactiveConnection($user, Channel::Telegram, $flow);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", interactiveSavePayload($flow))
        ->assertOk();

    expect(FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Interactive)->exists())->toBeTrue();
});

test('a flow with an interactive node can be assigned to any channel', function () {
    $user = interactiveTestUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Menu']);
    $flow->nodes()->create([
        'type' => NodeType::Interactive,
        'data' => ['interactive_type' => 'button', 'body' => 'Pick one', 'buttons' => [['id' => 'btn_a1', 'title' => 'Yes']]],
        'position_x' => 0,
        'position_y' => 0,
    ]);

    foreach ([Channel::Telegram, Channel::WhatsappOfficial] as $channel) {
        $connection = interactiveConnection($user, $channel);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/connections/{$connection->id}", ['name' => $connection->name, 'flow_id' => $flow->id])
            ->assertOk();

        expect($connection->fresh()->flow_id)->toBe($flow->id);
    }
});

test('the invalid branch is an accepted edge value alongside the option ids', function () {
    $user = interactiveTestUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Menu']);
    interactiveConnection($user, Channel::Telegram, $flow);

    $payload = interactiveSavePayload($flow);
    $payload['nodes'][1]['data']['invalid_message'] = 'Não entendi, responda com o número.';
    $payload['nodes'][1]['data']['invalid_attempts'] = 2;
    $payload['nodes'][] = [
        'id' => 'node-2', 'type' => 'message',
        'data' => ['message_type' => 'text', 'body' => 'Vou te transferir.'],
        'position_x' => 500, 'position_y' => 300,
    ];
    $payload['edges'][] = [
        'source_node_id' => 'node-1',
        'target_node_id' => 'node-2',
        'condition_value' => InteractiveNodes::BRANCH_INVALID,
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", $payload)
        ->assertOk();

    $node = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Interactive)->firstOrFail();

    expect($node->data['invalid_attempts'])->toBe(2)
        ->and($node->outgoingEdges()->where('condition_value', InteractiveNodes::BRANCH_INVALID)->exists())->toBeTrue();
});

test('invalid_attempts is clamped and defaults to one', function () {
    expect(InteractiveNodes::invalidAttempts([]))->toBe(InteractiveNodes::DEFAULT_INVALID_ATTEMPTS)
        ->and(InteractiveNodes::invalidAttempts(['invalid_attempts' => 0]))->toBe(1)
        ->and(InteractiveNodes::invalidAttempts(['invalid_attempts' => 99]))->toBe(InteractiveNodes::MAX_INVALID_ATTEMPTS);
});

test('a typed number picks the branch even with trailing punctuation', function () {
    $data = [
        'interactive_type' => 'button',
        'body' => 'Pick one',
        'buttons' => [['id' => 'btn_a1', 'title' => 'Suporte'], ['id' => 'btn_b2', 'title' => 'Vendas']],
    ];

    // On a channel without buttons typing the number *is* the interaction, so
    // the shapes people actually send have to count.
    expect(InteractiveNodes::matchOption($data, null, '2'))->toBe('btn_b2')
        ->and(InteractiveNodes::matchOption($data, null, ' 2. '))->toBe('btn_b2')
        ->and(InteractiveNodes::matchOption($data, null, '2)'))->toBe('btn_b2')
        ->and(InteractiveNodes::matchOption($data, null, 'vendas'))->toBe('btn_b2')
        // Still a sentence, not a pick.
        ->and(InteractiveNodes::matchOption($data, null, '2 caixas'))->toBeNull()
        ->and(InteractiveNodes::matchOption($data, null, '9'))->toBeNull();
});

test('every option becomes its own branch id, reused for the send payload', function () {
    $data = [
        'interactive_type' => 'list',
        'body' => 'Choose',
        'button_label' => 'Open menu',
        'sections' => [
            ['title' => 'Plans', 'rows' => [
                ['id' => 'row_x1', 'title' => 'Basic', 'description' => 'Cheap'],
                ['title' => 'Pro'], // authored before ids existed → positional fallback
            ]],
        ],
    ];

    expect(InteractiveNodes::options($data))->toBe([
        ['id' => 'row_x1', 'title' => 'Basic'],
        ['id' => 'row_1_2', 'title' => 'Pro'],
    ]);

    $payload = InteractiveNodes::sendPayload($data);

    expect($payload['button_label'])->toBe('Open menu')
        ->and($payload['sections'][0]['rows'][0]['id'])->toBe('row_x1')
        ->and($payload['sections'][0]['rows'][1]['id'])->toBe('row_1_2');
});

test('a carousel node saves, and its card buttons come back as branch values', function () {
    $user = interactiveTestUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Ofertas']);
    interactiveConnection($user, Channel::WhatsappOfficial, $flow);

    $payload = interactiveSavePayload($flow);
    $payload['nodes'][1]['data'] = [
        'interactive_type' => 'carousel',
        'body' => 'Ofertas pra você',
        'card_button_type' => 'quick_reply',
        'cards' => [
            [
                'header_type' => 'image',
                'header_url' => 'https://cdn.example.com/1.jpg',
                'body' => 'Categoria queridinha',
                'buttons' => [['id' => 'card_a1', 'title' => 'Ver ofertas']],
            ],
            [
                'header_type' => 'image',
                'header_url' => 'https://cdn.example.com/2.jpg',
                'body' => '40% off',
                'buttons' => [['id' => 'card_b1', 'title' => 'Ver ofertas']],
            ],
        ],
    ];
    // A card button id is a branch value, so it must survive the save as one.
    $payload['edges'][] = [
        'source_node_id' => 'node-1',
        'target_node_id' => (string) $payload['nodes'][0]['id'],
        'condition_value' => 'card_b1',
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", $payload)
        ->assertOk();

    $node = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Interactive)->first();

    expect($node->data['cards'])->toHaveCount(2)
        ->and(InteractiveNodes::options($node->data))->toBe([
            ['id' => 'card_a1', 'title' => 'Ver ofertas'],
            ['id' => 'card_b1', 'title' => 'Ver ofertas'],
        ]);
});

test('a reply is matched by id, then title, then position', function () {
    $data = [
        'interactive_type' => 'button',
        'body' => 'Pick one',
        'buttons' => [
            ['id' => 'btn_a1', 'title' => 'Yes'],
            ['id' => 'btn_b2', 'title' => 'No'],
        ],
    ];

    expect(InteractiveNodes::matchOption($data, 'btn_b2', 'anything'))->toBe('btn_b2')
        ->and(InteractiveNodes::matchOption($data, null, 'yes'))->toBe('btn_a1')
        ->and(InteractiveNodes::matchOption($data, null, '2'))->toBe('btn_b2')
        ->and(InteractiveNodes::matchOption($data, null, 'maybe'))->toBeNull();
});
