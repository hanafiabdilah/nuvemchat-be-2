<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunAiAgentTurn;
use App\Models\AiHubAgent;
use App\Models\AiHubTenant;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\ActionNodes;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/*
 * One AI Agent node without an agent or a welcome used to make every save of
 * the whole flow fail — the rest of the canvas included — for as long as it
 * stayed that way. Saving now takes it, and the executor is what makes that
 * safe: no agent → the node moves on the way a handoff does; no welcome → the
 * AI answers the opening itself. The assistant is still held to both fields.
 */

function incompleteAiEditor(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('incomplete-ai-editor-' . $tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate('flows.update', 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

/** start → the AI node under test → a message node the author was also working on. */
function incompleteAiPayload(Flow $flow, array $aiData): array
{
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    return [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'node-ai', 'type' => 'ai_agent', 'data' => $aiData, 'position_x' => 280, 'position_y' => 0],
            [
                'id' => 'node-msg',
                'type' => 'message',
                'data' => ['wait_for_reply' => false, 'messages' => [['message_type' => 'text', 'body' => 'Até logo!']]],
                'position_x' => 560,
                'position_y' => 0,
            ],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'node-ai', 'condition_value' => null],
            ['source_node_id' => 'node-ai', 'target_node_id' => 'node-msg', 'condition_value' => null],
        ],
    ];
}

/** The fixture's AI node with $data merged in, and an internal note wired after it. */
function aiNodeMissing(array $data): array
{
    [$conversation, $ai] = AiAgentFixtures::flow();

    $ai->update(['data' => array_merge($ai->data, $data)]);

    $note = FlowNode::create([
        'flow_id' => $ai->flow_id,
        'type' => NodeType::Action,
        'data' => ['type' => ActionNodes::INTERNAL_NOTE, 'parameters' => ['note' => 'Depois da IA']],
        'position_x' => 400,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $ai->id, 'target_node_id' => $note->id, 'condition_value' => null]);

    return [$conversation, $ai->fresh()];
}

function openConversationWith(Conversation $conversation, string $body): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.' . uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->startFlow($conversation->fresh());
}

// ---------------------------------------------------------------------------
// Saving
// ---------------------------------------------------------------------------

test('an AI node without an agent or a welcome does not stop the flow from saving', function (array $aiData) {
    $user = incompleteAiEditor();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Suporte']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", incompleteAiPayload($flow, $aiData))
        ->assertOk();

    // The rest of the canvas is what the old rule was throwing away.
    expect(FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Message)->exists())->toBeTrue()
        ->and(FlowNode::where('flow_id', $flow->id)->where('type', NodeType::AIAgent)->exists())->toBeTrue();
})->with([
    'the builder default' => [['ai_hub_agent_id' => '', 'welcoming_message' => '']],
    'nulls' => [['ai_hub_agent_id' => null, 'welcoming_message' => null]],
    'nothing filled in' => [['service_hours_behavior' => 'always_ai']],
]);

test('an agent that belongs to another workspace is still refused', function () {
    $user = incompleteAiEditor();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Suporte']);

    $stranger = incompleteAiEditor();
    $hubTenant = AiHubTenant::create([
        'tenant_id' => $stranger->tenant_id,
        'external_id' => 'Pingly_other',
        'name' => 'Pingly_other',
        'status' => 'ACTIVE',
    ]);
    $theirAgent = AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'hub-agent-other',
        'external_id' => 'agente_outro',
        'name' => 'Outro',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/save", incompleteAiPayload($flow, [
            'ai_hub_agent_id' => $theirAgent->id,
            'welcoming_message' => 'Oi!',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nodes.1.data.ai_hub_agent_id']);
});

test('the assistant is still held to an agent and a welcome', function () {
    $problems = FlowBlueprint::structureProblems(
        [
            ['key' => '1', 'type' => 'start', 'data' => null],
            ['key' => '2', 'type' => 'ai_agent', 'data' => ['ai_hub_agent_id' => null, 'welcoming_message' => '  ']],
        ],
        [['source_key' => '1', 'target_key' => '2', 'condition_value' => null]],
    );

    expect($problems)->toHaveCount(2)
        ->and(implode("\n", $problems))->toContain('ai_hub_agent_id')->toContain('welcoming_message');

    $complete = FlowBlueprint::structureProblems(
        [
            ['key' => '1', 'type' => 'start', 'data' => null],
            ['key' => '2', 'type' => 'ai_agent', 'data' => ['ai_hub_agent_id' => 7, 'welcoming_message' => 'Oi!']],
        ],
        [['source_key' => '1', 'target_key' => '2', 'condition_value' => null]],
    );

    expect($complete)->toBe([]);
});

// ---------------------------------------------------------------------------
// Running
// ---------------------------------------------------------------------------

test('an AI node without an agent moves on the way a handoff does, without greeting anyone', function () {
    AiAgentFixtures::fakeChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $ai] = aiNodeMissing(['ai_hub_agent_id' => null]);

    openConversationWith($conversation, 'meu pedido não chegou');

    Queue::assertNotPushed(RunAiAgentTurn::class);

    $state = FlowState::where('conversation_id', $conversation->id)->first();

    // No welcome on behalf of an AI that will never answer…
    expect(Message::where('conversation_id', $conversation->id)->where('body', 'Oi! Como posso ajudar?')->exists())->toBeFalse()
        // …the step wired after it ran, as it does after a handoff in "always AI"…
        ->and(Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::Info)->where('body', 'Depois da IA')->exists())->toBeTrue()
        // …and the conversation never visited the AI tab.
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Pending)
        ->and($state->state_data["_ai_handoff_reason_{$ai->id}"])->toBe('agent_missing');
});

test('in the handoff modes an AI node without an agent sends the customer to the human queue', function () {
    AiAgentFixtures::fakeChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = aiNodeMissing([
        'ai_hub_agent_id' => '',
        'service_hours_behavior' => 'handoff_in_hours',
    ]);

    openConversationWith($conversation, 'quero falar com alguém');

    Queue::assertNotPushed(RunAiAgentTurn::class);

    $conversation->refresh();

    expect($conversation->needs_human)->toBeTrue()
        ->and($conversation->handoff_reason)->toBe('agent_missing')
        ->and($conversation->user_id)->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        // The flow stopped at the handoff; nothing after the node ran.
        ->and(Message::where('conversation_id', $conversation->id)->where('body', 'Depois da IA')->exists())->toBeFalse();
});

test('an AI node without a welcome lets the AI answer the opening itself', function () {
    AiAgentFixtures::fakeChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $ai] = aiNodeMissing(['welcoming_message' => null]);

    // A bare greeting — the one message that normally gets only the welcome.
    // With no welcome to answer it, the AI must, or the customer hears nothing.
    openConversationWith($conversation, 'oi');

    Queue::assertPushed(RunAiAgentTurn::class, 1);

    $state = FlowState::where('conversation_id', $conversation->id)->first()->state_data;

    expect(Message::where('conversation_id', $conversation->id)->where('sender_type', SenderType::Outgoing)->count())->toBe(0)
        ->and($state["_ai_turns_{$ai->id}"])->toBe(1)
        // Zero, not the opening's id: the opening is inside the turn.
        ->and($state["_ai_last_processed_message_id_{$ai->id}"])->toBe(0);
});
