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

    expect(InteractiveNodes::flowUsesInteractive($flow->id))->toBeTrue();
});

test('a flow bound to another channel refuses an interactive node', function () {
    $user = interactiveTestUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Menu']);
    interactiveConnection($user, Channel::Telegram, $flow);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", interactiveSavePayload($flow))
        ->assertStatus(422)
        ->assertJsonValidationErrors('nodes');

    expect(InteractiveNodes::flowUsesInteractive($flow->id))->toBeFalse();
});

test('a flow with an interactive node cannot be assigned to a non whatsapp official connection', function () {
    $user = interactiveTestUser();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Menu']);
    $flow->nodes()->create([
        'type' => NodeType::Interactive,
        'data' => ['interactive_type' => 'button', 'body' => 'Pick one', 'buttons' => [['id' => 'btn_a1', 'title' => 'Yes']]],
        'position_x' => 0,
        'position_y' => 0,
    ]);

    $telegram = interactiveConnection($user, Channel::Telegram);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/connections/{$telegram->id}", ['name' => $telegram->name, 'flow_id' => $flow->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('flow_id');

    expect($telegram->fresh()->flow_id)->toBeNull();

    $whatsapp = interactiveConnection($user, Channel::WhatsappOfficial);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/connections/{$whatsapp->id}", ['name' => $whatsapp->name, 'flow_id' => $flow->id])
        ->assertOk();

    expect($whatsapp->fresh()->flow_id)->toBe($flow->id);
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
