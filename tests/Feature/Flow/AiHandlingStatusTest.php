<?php

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationHandoff;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\User;
use App\Observers\ConversationObserver;
use App\Services\Flow\ActionNodes;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/**
 * The "AI" tab of the inbox is a status the flow engine owns.
 *
 * A conversation is `ai_handling` for exactly as long as an AI Agent node is
 * serving it, and back in the Pending queue the moment the AI stops — whatever
 * the reason — so nobody is ever left waiting on a bot that has given up. The
 * engine's own moves are bookkeeping: they broadcast so every inbox moves the
 * row between tabs, but they write no status note and stop nothing. A person
 * taking the conversation ("Assumir da IA") is an event, and stops the flow.
 */

/** The customer writes, and the webhook hands the message to the flow. */
function aiStatusIncoming(Conversation $conversation, string $body): Message
{
    $message = $conversation->messages()->create([
        'external_id' => 'wamid.' . uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), $body);

    return $message;
}

/**
 * Outbound WhatsApp and the hub both answer, and every run gets its own id —
 * the runs table keys on it, so a conversation answered twice needs two.
 */
function aiStatusFakeHub(string $reply): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT' . uniqid()]]]),
        'api-ia.ipbr.pro/*' => fn () => Http::response([
            'id' => 'run_' . uniqid(),
            'status' => 'COMPLETED',
            'output' => ['message' => $reply, 'handoff' => false],
        ]),
    ]);
}

/** Change the AI node's settings after the fixture built it. */
function aiStatusNode(FlowNode $node, array $data): FlowNode
{
    $node->forceFill(['data' => array_merge($node->data ?? [], $data)])->save();

    return $node->fresh();
}

/** A step wired after the AI node, for the modes that move past it. */
function aiStatusStepAfter(FlowNode $ai): FlowNode
{
    $next = FlowNode::create([
        'flow_id' => $ai->flow_id,
        'type' => NodeType::Action,
        'data' => [
            'type' => ActionNodes::INTERNAL_NOTE,
            'parameters' => ['note' => 'Passou pela IA'],
        ],
        'position_x' => 200,
        'position_y' => 0,
    ]);

    FlowEdge::create([
        'source_node_id' => $ai->id,
        'target_node_id' => $next->id,
        'condition_value' => null,
    ]);

    return $next;
}

/** An agent of the same workspace who can open this conversation's connection. */
function aiStatusAgent(Conversation $conversation): User
{
    $connection = $conversation->connection;

    $agent = User::factory()->create([
        'tenant_id' => $connection->tenant_id,
        'name' => 'Marina Alves',
    ]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);

    return $agent->fresh();
}

/** Status notes written into the thread (the "Pendente → Ativo" lines). */
function aiStatusNotes(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->orderBy('id')
        ->get()
        ->map(fn (Message $message) => $message->meta['info']['code'] ?? null)
        ->filter(fn ($code) => in_array($code, [
            ConversationObserver::INFO_STATUS_CHANGED,
            ConversationObserver::INFO_STATUS_CHANGED_BY,
        ], true))
        ->values()
        ->all();
}

function aiStatusFlowState(Conversation $conversation): ?FlowState
{
    return FlowState::where('conversation_id', $conversation->id)->first();
}

/** Run one of the executor's own steps, the way the engine would. */
function aiStatusRoute(Conversation $conversation, FlowNode $node, string $reason, bool $aiCanContinue = false): void
{
    $state = aiStatusFlowState($conversation);

    (function () use ($state, $node, $reason, $aiCanContinue) {
        $this->routeHandoff($state, $node, $reason, $aiCanContinue);
    })->call(new FlowExecutor);
}

// ---------------------------------------------------------------------------
// Into the AI tab
// ---------------------------------------------------------------------------

test('an AI agent node that starts serving moves the conversation to the AI tab, quietly', function () {
    Event::fake([ConversationUpdated::class]);

    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::fakeChannelsAndHub();

    Conversation::whereKey($conversation->id)->update(['updated_at' => now()->subHour()]);

    AiAgentFixtures::openWithWelcome($conversation);

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::AiHandling)
        ->and($fresh->user_id)->toBeNull()
        ->and($fresh->needs_human)->toBeFalse()
        // The delta sync cursor moves, so a dashboard that was offline sees it.
        ->and($fresh->updated_at->gt(now()->subMinute()))->toBeTrue();

    // Every open inbox moves the row from Pendente to IA as it happens.
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event) =>
        $event->conversation->id === $conversation->id
        && $event->conversation->status === ConversationStatus::AiHandling);

    // Bookkeeping, not an event: no "Pendente → IA" line in the thread.
    expect(aiStatusNotes($conversation))->toBe([])
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Running);
});

test('the AI keeps answering while the conversation is in the AI tab', function () {
    [$conversation] = AiAgentFixtures::flow();
    aiStatusFakeHub('O prazo é de 3 dias úteis.');
    AiAgentFixtures::openWithWelcome($conversation);

    aiStatusIncoming($conversation, 'qual o prazo de entrega?');
    aiStatusIncoming($conversation, 'e para o interior?');

    expect(AiAgentFixtures::hubRuns())->toHaveCount(2)
        ->and(Message::where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::Outgoing)
            ->where('body', 'O prazo é de 3 dias úteis.')
            ->count())->toBe(2)
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::AiHandling)
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Running);
});

test('a node that sends the customer straight to a person never shows as AI', function () {
    Event::fake([ConversationUpdated::class, ConversationHandoff::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    // No service hours configured reads as open, so this is "within hours".
    aiStatusNode($node, ['service_hours_behavior' => 'human_only_in_hours']);
    AiAgentFixtures::fakeChannelsAndHub();

    AiAgentFixtures::openWithWelcome($conversation);

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::Pending)
        ->and($fresh->needs_human)->toBeTrue()
        ->and($fresh->handoff_reason)->toBe('service_hours')
        ->and(AiAgentFixtures::hubRuns())->toBe([]);

    Event::assertNotDispatched(ConversationUpdated::class, fn (ConversationUpdated $event) =>
        $event->conversation->status === ConversationStatus::AiHandling);
    Event::assertDispatched(ConversationHandoff::class);
});

test('group and e-mail conversations are never put in the AI tab', function () {
    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::fakeChannelsAndHub();

    // A group never reaches a flow at all…
    $conversation->forceFill(['type' => ConversationType::Group])->save();
    AiAgentFixtures::openWithWelcome($conversation->fresh());

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Pending)
        ->and(aiStatusFlowState($conversation))->toBeNull();

    // …and the guard holds even if something calls it directly.
    $mark = fn (Conversation $target) => (function () use ($target) {
        $this->markAiHandling($target);
    })->call(new FlowExecutor);

    $mark($conversation->fresh());
    expect($conversation->fresh()->status)->toBe(ConversationStatus::Pending);

    // E-mail is a shared inbox no AI serves.
    $conversation->forceFill(['type' => ConversationType::Private])->save();
    $conversation->connection->forceFill(['channel' => Channel::Email])->save();

    $mark($conversation->fresh());
    expect($conversation->fresh()->status)->toBe(ConversationStatus::Pending);
});

// ---------------------------------------------------------------------------
// Out of the AI tab
// ---------------------------------------------------------------------------

test('a handoff puts the conversation back in the queue for a person, without a status note', function () {
    Event::fake([ConversationUpdated::class, ConversationHandoff::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    aiStatusNode($node, ['service_hours_behavior' => 'handoff_in_hours']);
    AiAgentFixtures::fakeChannelsAndHub('Vou chamar um atendente para você.', [], ['handoff' => true]);
    AiAgentFixtures::openWithWelcome($conversation);

    expect($conversation->fresh()->status)->toBe(ConversationStatus::AiHandling);

    aiStatusIncoming($conversation, 'quero falar com uma pessoa');

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::Pending)
        ->and($fresh->needs_human)->toBeTrue()
        ->and($fresh->handoff_reason)->toBe('ai_requested')
        ->and($fresh->user_id)->toBeNull()
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Stopped)
        // The badge, the toast and the live board say it; the thread does not
        // need a fourth line.
        ->and(aiStatusNotes($conversation))->toBe([]);

    Event::assertDispatched(ConversationHandoff::class, fn (ConversationHandoff $event) =>
        $event->reason === 'ai_requested');
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event) =>
        $event->conversation->status === ConversationStatus::Pending && $event->conversation->needs_human);
});

test('every way the AI gives up within service hours ends in the queue', function (string $reason) {
    [$conversation, $node] = AiAgentFixtures::flow();
    $node = aiStatusNode($node, ['service_hours_behavior' => 'handoff_in_hours']);
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);

    expect($conversation->fresh()->status)->toBe(ConversationStatus::AiHandling);

    aiStatusRoute($conversation, $node, $reason);

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::Pending)
        ->and($fresh->needs_human)->toBeTrue()
        ->and($fresh->handoff_reason)->toBe($reason)
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Stopped)
        ->and(aiStatusNotes($conversation))->toBe([]);
})->with([
    'the AI asked for a person' => 'ai_requested',
    'the run failed' => 'error',
    'the agent is gone' => 'agent_missing',
    'too many turns' => 'max_turns_exceeded',
    'the plan ran out of AI runs' => 'ai_quota_exceeded',
    'the prepaid balance is empty' => 'credit_exhausted',
]);

test('a failed run hands the conversation to a person end to end', function () {
    [$conversation, $node] = AiAgentFixtures::flow();
    aiStatusNode($node, ['service_hours_behavior' => 'handoff_in_hours']);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT' . uniqid()]]]),
        'api-ia.ipbr.pro/*' => Http::response(['message' => 'internal error', 'statusCode' => 500], 500),
    ]);

    AiAgentFixtures::openWithWelcome($conversation);
    aiStatusIncoming($conversation, 'meu pedido não chegou');

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::Pending)
        ->and($fresh->needs_human)->toBeTrue()
        ->and($fresh->handoff_reason)->toBe('error');
});

test('when the AI node passes the conversation on, it leaves the AI tab and the flow continues', function () {
    [$conversation, $node] = AiAgentFixtures::flow();
    $node = aiStatusNode($node, ['service_hours_behavior' => 'always_ai']);
    $next = aiStatusStepAfter($node);
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);

    expect($conversation->fresh()->status)->toBe(ConversationStatus::AiHandling);

    // The agent behind the node is gone, so the AI cannot answer; in this mode
    // that sends the flow on to the next step rather than to a person.
    aiStatusNode($node, ['ai_hub_agent_id' => 999999]);
    aiStatusIncoming($conversation, 'preciso de ajuda');

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::Pending)
        ->and($fresh->needs_human)->toBeFalse()
        ->and(aiStatusFlowState($conversation)->current_node_id)->toBe($next->id)
        ->and(Message::where('conversation_id', $conversation->id)->where('body', 'Passou pela IA')->exists())->toBeTrue()
        ->and(aiStatusNotes($conversation))->toBe([]);
});

test('the AI answering again puts the conversation back in the AI tab', function () {
    [$conversation, $node] = AiAgentFixtures::flow();
    $node = aiStatusNode($node, ['service_hours_behavior' => 'always_ai']);
    $agentId = $node->data['ai_hub_agent_id'];
    AiAgentFixtures::fakeChannelsAndHub('Agora consigo ajudar.');
    AiAgentFixtures::openWithWelcome($conversation);

    // Nothing is wired after the node, so after giving up once the flow stays
    // parked on it — and the next message is the AI's again.
    aiStatusNode($node, ['ai_hub_agent_id' => 999999]);
    aiStatusIncoming($conversation, 'primeira pergunta');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Pending)
        ->and(aiStatusFlowState($conversation)->current_node_id)->toBe($node->id);

    aiStatusNode($node, ['ai_hub_agent_id' => $agentId]);
    aiStatusIncoming($conversation, 'segunda pergunta');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::AiHandling)
        ->and(Message::where('conversation_id', $conversation->id)->where('body', 'Agora consigo ajudar.')->exists())->toBeTrue();
});

test('outside service hours a handoff request keeps the conversation with the AI', function () {
    [$conversation, $node] = AiAgentFixtures::flow();
    $node = aiStatusNode($node, ['service_hours_behavior' => 'handoff_in_hours']);
    // Enabled with no open range on any day: always closed.
    $conversation->connection->forceFill(['service_hours' => ['enabled' => true, 'days' => []]])->save();
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);

    aiStatusRoute($conversation, $node, 'ai_requested', aiCanContinue: true);

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::AiHandling)
        ->and($fresh->needs_human)->toBeFalse()
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Running);
});

test('a conversation left behind by a deleted flow or node goes back to the queue on the next message', function (string $what) {
    Event::fake([ConversationUpdated::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);

    expect($conversation->fresh()->status)->toBe(ConversationStatus::AiHandling);

    match ($what) {
        'flow' => Flow::whereKey($node->flow_id)->first()->delete(),
        'node' => $node->delete(),
    };

    aiStatusIncoming($conversation, 'alô?');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Pending)
        ->and(AiAgentFixtures::hubRuns())->toBe([]);

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event) =>
        $event->conversation->status === ConversationStatus::Pending);
})->with(['flow', 'node']);

// ---------------------------------------------------------------------------
// A person takes over
// ---------------------------------------------------------------------------

test('taking over from the AI makes the conversation Active and silences the flow', function () {
    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);
    $agent = aiStatusAgent($conversation);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/accept")
        ->assertOk();

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::Active)
        ->and($fresh->user_id)->toBe($agent->id)
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Stopped)
        // "Marina took this conversation" says it; no second status line.
        ->and(aiStatusNotes($conversation))->toBe([]);

    aiStatusIncoming($conversation, 'ainda está aí?');

    expect(AiAgentFixtures::hubRuns())->toBe([])
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Active);
});

test('taking several conversations out of the AI at once works the same way', function () {
    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);
    $agent = aiStatusAgent($conversation);

    $this->actingAs($agent, 'sanctum')
        ->postJson('/api/conversations/bulk-status', [
            'ids' => [$conversation->id],
            'status' => ConversationStatus::Active->value,
        ])
        ->assertOk();

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Active)
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Stopped);
});

test('a reply still being written when a person takes over is never sent over them', function () {
    [$conversation] = AiAgentFixtures::flow();
    $agent = aiStatusAgent($conversation);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT' . uniqid()]]]),
        'api-ia.ipbr.pro/*' => function () use ($conversation, $agent) {
            // The agent presses "Assumir da IA" while the model is thinking.
            Conversation::whereKey($conversation->id)->update([
                'status' => ConversationStatus::Active->value,
                'user_id' => $agent->id,
            ]);

            return Http::response([
                'id' => 'run_late',
                'status' => 'COMPLETED',
                'output' => ['message' => 'Resposta atrasada da IA', 'handoff' => true],
            ]);
        },
    ]);

    AiAgentFixtures::openWithWelcome($conversation);
    aiStatusIncoming($conversation, 'meu pedido não chegou');

    $fresh = $conversation->fresh();

    expect(AiAgentFixtures::hubRuns())->toHaveCount(1)
        ->and(Message::where('body', 'Resposta atrasada da IA')->exists())->toBeFalse()
        // Nor does the handoff the run asked for undo the assignment.
        ->and($fresh->status)->toBe(ConversationStatus::Active)
        ->and($fresh->user_id)->toBe($agent->id)
        ->and($fresh->needs_human)->toBeFalse();
});

test('a late handoff never pulls a conversation back from a person', function () {
    [$conversation, $node] = AiAgentFixtures::flow();
    $node = aiStatusNode($node, ['service_hours_behavior' => 'handoff_in_hours']);
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);
    $agent = aiStatusAgent($conversation);

    // Taken by a person, but the flow state is still the running one the
    // handoff was computed from.
    Conversation::whereKey($conversation->id)->update([
        'status' => ConversationStatus::Active->value,
        'user_id' => $agent->id,
    ]);

    aiStatusRoute($conversation, $node, 'error');

    $fresh = $conversation->fresh();

    expect($fresh->status)->toBe(ConversationStatus::Active)
        ->and($fresh->user_id)->toBe($agent->id)
        ->and($fresh->needs_human)->toBeFalse();
});

test('resolving a conversation while the AI serves it stops the flow', function () {
    [$conversation] = AiAgentFixtures::flow();
    AiAgentFixtures::fakeChannelsAndHub();
    AiAgentFixtures::openWithWelcome($conversation);

    $conversation->fresh()->markResolved();

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Resolved)
        ->and(aiStatusFlowState($conversation)->status)->toBe(FlowStateStatus::Stopped);

    aiStatusIncoming($conversation, 'obrigado');

    expect(AiAgentFixtures::hubRuns())->toBe([]);
});
