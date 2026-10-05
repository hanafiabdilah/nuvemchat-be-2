<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Http\Controllers\Api\ConversationFlowController;
use App\Jobs\RunFlowIntervalNode;
use App\Jobs\RunFlowMessageNode;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\IntervalNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
    Queue::fake();
});

function manualFlowOwner(): User
{
    $user = User::factory()->create(['name' => 'Ana']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->givePermissionTo(Permission::findOrCreate('conversations.manage-flow', 'web'));

    return $user->fresh();
}

function manualFlowConnection(User $owner, ?Flow $flow = null, Channel $channel = Channel::WhatsappOfficial): Connection
{
    $connection = Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => $channel,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow?->id,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);

    $owner->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function manualFlowConversation(Connection $connection, ConversationStatus $status = ConversationStatus::Pending, ?User $assignee = null): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'external_id' => '5511988887777',
        'name' => 'Bia',
        'username' => '5511988887777',
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511988887777',
        'status' => $status,
        'user_id' => $assignee?->id,
    ]);
}

function manualFlowNode(Flow $flow, NodeType $type, ?array $data = null): FlowNode
{
    return $flow->nodes()->create(['type' => $type, 'data' => $data, 'position_x' => 0, 'position_y' => 0]);
}

/** start → one text message saying `$text`. */
function manualFlowSaying(User $owner, string $name, string $text): Flow
{
    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => $name]);
    $start = manualFlowNode($flow, NodeType::Start);
    $message = manualFlowNode($flow, NodeType::Message, [
        'messages' => [['message_type' => 'text', 'body' => $text, 'delay' => 0]],
    ]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $message->id]);

    return $flow;
}

/** start → interval(600s) → message. Parks on the interval. */
function manualFlowWaiting(User $owner): Flow
{
    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Espera']);
    $start = manualFlowNode($flow, NodeType::Start);
    $interval = manualFlowNode($flow, NodeType::Interval, array_merge(IntervalNodes::defaults(), ['seconds' => 600]));
    $message = manualFlowNode($flow, NodeType::Message, [
        'messages' => [['message_type' => 'text', 'body' => 'Depois da espera', 'delay' => 0]],
    ]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $interval->id]);
    FlowEdge::create(['source_node_id' => $interval->id, 'target_node_id' => $message->id]);

    return $flow;
}

function manualFlowBodies(Conversation $conversation): array
{
    return $conversation->messages()
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

function manualFlowNotes(Conversation $conversation): array
{
    return $conversation->messages()
        ->where('message_type', MessageType::Info)
        ->orderBy('id')
        ->get()
        ->map(fn ($m) => $m->meta['info']['code'] ?? null)
        ->all();
}

test('lists the workspace flows by name, and nobody else\'s', function () {
    $owner = manualFlowOwner();
    $other = manualFlowOwner();
    manualFlowSaying($owner, 'Vendas', 'a');
    manualFlowSaying($owner, 'Boas-vindas', 'b');
    manualFlowSaying($other, 'Alheio', 'c');
    $conversation = manualFlowConversation(manualFlowConnection($owner));

    $this->actingAs($owner, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/flows")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Boas-vindas')
        ->assertJsonPath('data.1.name', 'Vendas')
        ->assertJsonCount(2, 'data');
});

test('starts a chosen flow in a queued conversation that had none', function () {
    $owner = manualFlowOwner();
    $flow = manualFlowSaying($owner, 'Vendas', 'Olá da Vendas');
    $conversation = manualFlowConversation(manualFlowConnection($owner));

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/trigger", ['flow_id' => $flow->id])
        ->assertOk()
        ->assertJsonPath('data.flow_state.flow_id', $flow->id);

    expect(manualFlowBodies($conversation))->toBe(['Olá da Vendas'])
        ->and(manualFlowNotes($conversation))->toBe([ConversationFlowController::INFO_TRIGGERED]);

    $note = $conversation->messages()->where('message_type', MessageType::Info)->sole();
    expect($note->meta['info']['params'])->toBe(['by' => 'Ana', 'flow' => 'Vendas']);
});

test('starting a flow in a conversation the agent holds hands it back to the queue', function () {
    $owner = manualFlowOwner();
    $flow = manualFlowSaying($owner, 'Pesquisa', 'Como foi?');
    $conversation = manualFlowConversation(manualFlowConnection($owner), ConversationStatus::Active, $owner);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/trigger", ['flow_id' => $flow->id])
        ->assertOk();

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->user_id)->toBeNull()
        ->and(manualFlowBodies($conversation))->toBe(['Como foi?'])
        // One note: the trigger says it all, the status line would repeat it.
        ->and(manualFlowNotes($conversation))->toBe([ConversationFlowController::INFO_TRIGGERED]);
});

test('starting another flow replaces the running one and keeps its variables', function () {
    $owner = manualFlowOwner();
    $waiting = manualFlowWaiting($owner);
    $other = manualFlowSaying($owner, 'Outro', 'Olá {{nome}}');
    $conversation = manualFlowConversation(manualFlowConnection($owner, $waiting));

    (new FlowExecutor)->startFlow($conversation);
    $state = FlowState::where('conversation_id', $conversation->id)->sole();
    $state->update(['state_data' => array_merge($state->state_data, ['nome' => 'Bia'])]);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/trigger", ['flow_id' => $other->id])
        ->assertOk();

    $state->refresh();

    expect(FlowState::where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and((int) $state->flow_id)->toBe((int) $other->id)
        ->and($state->state_data['nome'])->toBe('Bia')
        ->and(array_filter(array_keys($state->state_data), fn ($k) => str_starts_with($k, '_interval_')))->toBe([])
        ->and(manualFlowBodies($conversation))->toBe(['Olá Bia']);
});

test('refuses a flow from another workspace, a closed thread, and a colleague\'s thread', function () {
    $owner = manualFlowOwner();
    $stranger = manualFlowOwner();
    $flow = manualFlowSaying($owner, 'Vendas', 'a');
    $foreign = manualFlowSaying($stranger, 'Alheio', 'b');
    $connection = manualFlowConnection($owner);

    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);
    $agent->givePermissionTo('conversations.manage-flow');

    $queued = manualFlowConversation($connection);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$queued->id}/flow/trigger", ['flow_id' => $foreign->id])
        ->assertStatus(422);

    $queued->update(['status' => ConversationStatus::Resolved]);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$queued->id}/flow/trigger", ['flow_id' => $flow->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'conversation_resolved');

    $queued->update(['status' => ConversationStatus::Active, 'user_id' => $owner->id]);

    $this->actingAs($agent->fresh(), 'sanctum')
        ->postJson("/api/conversations/{$queued->id}/flow/trigger", ['flow_id' => $flow->id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'conversation_not_yours');

    expect(manualFlowBodies($queued))->toBe([]);
});

test('needs the permission', function () {
    $owner = manualFlowOwner();
    $flow = manualFlowSaying($owner, 'Vendas', 'a');
    $connection = manualFlowConnection($owner);
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);
    $conversation = manualFlowConversation($connection);

    $this->actingAs($agent->fresh(), 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/trigger", ['flow_id' => $flow->id])
        ->assertStatus(403);
});

test('a paused flow answers nobody and its timer stands down', function () {
    $owner = manualFlowOwner();
    $flow = manualFlowWaiting($owner);
    $conversation = manualFlowConversation(manualFlowConnection($owner, $flow));

    (new FlowExecutor)->startFlow($conversation);
    $state = FlowState::where('conversation_id', $conversation->id)->sole();
    $token = $state->state_data[IntervalNodes::claimKey($state->current_node_id)]['token'];

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/pause")
        ->assertOk()
        ->assertJsonPath('data.flow_state.status', 'paused');

    // The interval elapses while paused: nothing moves, nothing is sent.
    (new FlowExecutor)->runIntervalElapsed($state->id, $state->current_node_id, $token);
    (new FlowExecutor)->resumeFlow($conversation->refresh(), 'oi?');

    expect($state->refresh()->status)->toBe(FlowStateStatus::Paused)
        ->and(manualFlowBodies($conversation))->toBe([])
        ->and(manualFlowNotes($conversation))->toBe([ConversationFlowController::INFO_PAUSED]);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/pause")
        ->assertStatus(409)
        ->assertJsonPath('code', 'flow_not_running');
});

test('resuming restarts an interval the flow was paused on', function () {
    $owner = manualFlowOwner();
    $flow = manualFlowWaiting($owner);
    $conversation = manualFlowConversation(manualFlowConnection($owner, $flow));

    (new FlowExecutor)->startFlow($conversation);
    $state = FlowState::where('conversation_id', $conversation->id)->sole();
    $nodeId = $state->current_node_id;
    $old = $state->state_data[IntervalNodes::claimKey($nodeId)]['token'];

    $this->actingAs($owner, 'sanctum')->postJson("/api/conversations/{$conversation->id}/flow/pause")->assertOk();
    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/resume")
        ->assertOk()
        ->assertJsonPath('data.flow_state.status', 'running');

    $state->refresh();
    $new = $state->state_data[IntervalNodes::claimKey($nodeId)]['token'];

    expect($new)->not->toBe($old)
        ->and($state->state_data)->not->toHaveKey('_paused');

    Queue::assertPushed(RunFlowIntervalNode::class, 2);

    // The job from before the pause is nobody's; the new one moves the flow.
    (new FlowExecutor)->runIntervalElapsed($state->id, $nodeId, $old);
    expect(manualFlowBodies($conversation))->toBe([]);

    (new FlowExecutor)->runIntervalElapsed($state->id, $nodeId, $new);
    expect(manualFlowBodies($conversation))->toBe(['Depois da espera']);
});

test('resuming a message sequence carries on with the bubble that was next', function () {
    $owner = manualFlowOwner();
    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Sequência']);
    $start = manualFlowNode($flow, NodeType::Start);
    $message = manualFlowNode($flow, NodeType::Message, [
        'messages' => [
            ['message_type' => 'text', 'body' => 'Primeira', 'delay' => 0],
            ['message_type' => 'text', 'body' => 'Segunda', 'delay' => 30],
        ],
    ]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $message->id]);
    $conversation = manualFlowConversation(manualFlowConnection($owner, $flow));

    (new FlowExecutor)->startFlow($conversation);

    // Whatever the engine did with the first bubble (inline or queued), run
    // every queued step until the sequence is waiting on the second.
    $state = FlowState::where('conversation_id', $conversation->id)->sole();
    $chain = fn () => $state->refresh()->state_data["_message_chain_{$message->id}"] ?? null;

    if (($chain()['index'] ?? null) === 0) {
        (new FlowExecutor)->runScheduledMessageItem($state->id, $message->id, 0, $chain()['token']);
    }

    expect(manualFlowBodies($conversation))->toBe(['Primeira'])
        ->and($chain()['index'])->toBe(1);

    $this->actingAs($owner, 'sanctum')->postJson("/api/conversations/{$conversation->id}/flow/pause")->assertOk();
    $this->actingAs($owner, 'sanctum')->postJson("/api/conversations/{$conversation->id}/flow/resume")->assertOk();

    $resumed = $chain();
    expect($resumed['index'])->toBe(1);

    (new FlowExecutor)->runScheduledMessageItem($state->id, $message->id, 1, $resumed['token']);

    expect(manualFlowBodies($conversation))->toBe(['Primeira', 'Segunda']);
    Queue::assertPushed(RunFlowMessageNode::class);
});

test('a paused flow cannot resume once a person holds the conversation', function () {
    $owner = manualFlowOwner();
    $flow = manualFlowWaiting($owner);
    $conversation = manualFlowConversation(manualFlowConnection($owner, $flow));

    (new FlowExecutor)->startFlow($conversation);
    $this->actingAs($owner, 'sanctum')->postJson("/api/conversations/{$conversation->id}/flow/pause")->assertOk();

    FlowState::where('conversation_id', $conversation->id)->update(['status' => FlowStateStatus::Paused->value]);
    Conversation::whereKey($conversation->id)->update(['status' => ConversationStatus::Active->value, 'user_id' => $owner->id]);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/resume")
        ->assertStatus(409)
        ->assertJsonPath('code', 'conversation_not_in_queue');
});

test('flows cannot be steered in a group or an e-mail thread', function () {
    $owner = manualFlowOwner();
    $flow = manualFlowSaying($owner, 'Vendas', 'a');
    $conversation = manualFlowConversation(manualFlowConnection($owner, null, Channel::Email));

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/flow/trigger", ['flow_id' => $flow->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'flow_not_supported');
});
