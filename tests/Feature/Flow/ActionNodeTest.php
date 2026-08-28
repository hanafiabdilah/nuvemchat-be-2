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
use App\Services\Flow\ActionNodes;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]));

/**
 * start → action → message.
 *
 * The trailing message is how each test reads the answer to the only question
 * that distinguishes the three actions: did somebody take the conversation? If
 * they did, the bot has to fall silent, and "Anything else?" arriving after a
 * handoff is the exact failure this node has to avoid.
 */
function actionNodeFixture(array $actionData): array
{
    $owner = User::factory()->create(['name' => 'Owner']);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Router']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $action = $flow->nodes()->create([
        'type' => NodeType::Action,
        'data' => $actionData,
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $after = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Anything else?', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $action->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $action->id, 'target_node_id' => $after->id, 'condition_value' => null]);

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

    return [$conversation, $tenant, $connection, compact('start', 'action', 'after')];
}

/** An agent of this tenant with access to this connection. Online unless told otherwise. */
function actionNodeAgent(Tenant $tenant, Connection $connection, string $name, bool $online = true): User
{
    $agent = User::factory()->create(['tenant_id' => $tenant->id, 'name' => $name]);
    $agent->connections()->attach($connection->id);
    $agent->forceFill(['last_seen_at' => $online ? now() : now()->subHour()])->save();

    return $agent;
}

/**
 * Rewrite the action node's data once the agent it points at exists.
 *
 * Through the model rather than the query builder, so the `data` array cast is
 * the one that decides how it is stored — writing raw JSON here would test a
 * shape the app never produces.
 */
function setActionNodeData(Conversation $conversation, array $data): void
{
    $conversation->connection->flow->nodes()
        ->where('type', NodeType::Action)
        ->first()
        ->update(['data' => $data]);
}

/** Bodies of the real (channel-bound) messages sent to the customer. */
function actionNodeSentBodies(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Text)
        ->pluck('body')
        ->all();
}

test('assign_agent hands the conversation to the named agent and silences the bot', function () {
    [$conversation, $tenant, $connection] = actionNodeFixture([
        'type' => ActionNodes::ASSIGN_AGENT,
        'parameters' => [],
    ]);

    $agent = actionNodeAgent($tenant, $connection, 'Bruno');

    // Written after the fixture, once the agent id exists.
    setActionNodeData($conversation, ['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => ['agent_id' => $agent->id]]);

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    expect($conversation->user_id)->toBe($agent->id)
        ->and($conversation->status)->toBe(ConversationStatus::Active)
        ->and($conversation->needs_human)->toBeFalsy()
        ->and(actionNodeSentBodies($conversation))->not->toContain('Anything else?');
});

test('an assignment explains itself in the thread', function () {
    [$conversation, $tenant, $connection] = actionNodeFixture(['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => []]);

    $agent = actionNodeAgent($tenant, $connection, 'Bruno');
    setActionNodeData($conversation, ['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => ['agent_id' => $agent->id]]);

    (new FlowExecutor)->startFlow($conversation);

    $notes = Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->get();

    // Its own code, not `conversation_assigned`: that one reads "Bruno took
    // this conversation", and Bruno took nothing.
    $assignment = $notes->firstWhere(fn ($m) => ($m->meta['info']['code'] ?? null) === ActionNodes::INFO_ASSIGNED_BY_FLOW);

    expect($assignment)->not->toBeNull()
        ->and($assignment->meta['info']['params']['to'])->toBe('Bruno');

    // And exactly one note: the pending → active status note is suppressed
    // because the assignment note above already says more than it does.
    expect($notes)->toHaveCount(1);
});

test('an offline agent sends the conversation to the queue instead of an empty chair', function () {
    [$conversation, $tenant, $connection] = actionNodeFixture(['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => []]);

    $agent = actionNodeAgent($tenant, $connection, 'Bruno', online: false);
    setActionNodeData($conversation, ['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => ['agent_id' => $agent->id]]);

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    expect($conversation->user_id)->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->needs_human)->toBeTruthy()
        ->and($conversation->handoff_reason)->toBe(ActionNodes::REASON_AGENT_OFFLINE)
        ->and(actionNodeSentBodies($conversation))->not->toContain('Anything else?');
});

test('assign_anyway overrules presence but never connection access', function () {
    [$conversation, $tenant, $connection] = actionNodeFixture(['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => []]);

    $agent = actionNodeAgent($tenant, $connection, 'Bruno', online: false);
    setActionNodeData($conversation, [
        'type' => ActionNodes::ASSIGN_AGENT,
        'parameters' => ['agent_id' => $agent->id, 'when_unavailable' => ActionNodes::UNAVAILABLE_ASSIGN_ANYWAY],
    ]);

    (new FlowExecutor)->startFlow($conversation);

    expect($conversation->refresh()->user_id)->toBe($agent->id);
});

test('an agent without access to the connection is refused, not assigned', function () {
    [$conversation, $tenant] = actionNodeFixture(['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => []]);

    // Same tenant, online, but the connection was never granted to them —
    // revoking access is meant to close the threads they hold, so a flow must
    // not be the way back in.
    $agent = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Carla']);
    $agent->forceFill(['last_seen_at' => now()])->save();

    setActionNodeData($conversation, ['type' => ActionNodes::ASSIGN_AGENT, 'parameters' => ['agent_id' => $agent->id]]);

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    expect($conversation->user_id)->toBeNull()
        ->and($conversation->needs_human)->toBeTruthy()
        ->and($conversation->handoff_reason)->toBe(ActionNodes::REASON_AGENT_UNAVAILABLE);
});

test('an assign_agent node with no agent picked is skipped, not acted on', function () {
    [$conversation] = actionNodeFixture([
        'type' => ActionNodes::ASSIGN_AGENT,
        'parameters' => [],
    ]);

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    // A half-finished node is invisible, not a dead end and not a handoff.
    expect($conversation->user_id)->toBeNull()
        ->and($conversation->needs_human)->toBeFalsy()
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and(actionNodeSentBodies($conversation))->toContain('Anything else?');
});

test('transfer_human rings for an agent and stops the flow', function () {
    [$conversation] = actionNodeFixture([
        'type' => ActionNodes::TRANSFER_HUMAN,
        'parameters' => [],
    ]);

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    expect($conversation->needs_human)->toBeTruthy()
        ->and($conversation->user_id)->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->handoff_at)->not->toBeNull()
        // A code, not a sentence: the dashboard renders the reason in the
        // reader's language.
        ->and($conversation->handoff_reason)->toBe(ActionNodes::REASON_REQUESTED)
        ->and(actionNodeSentBodies($conversation))->not->toContain('Anything else?');
});

test('internal_note writes into the thread, resolves variables, and lets the flow continue', function () {
    [$conversation] = actionNodeFixture([
        'type' => ActionNodes::INTERNAL_NOTE,
        'parameters' => ['note' => 'Customer {{contact.name}} came from the pricing page.'],
    ]);

    (new FlowExecutor)->startFlow($conversation);

    $note = Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->first();

    expect($note)->not->toBeNull()
        ->and($note->body)->toBe('Customer Ana came from the pricing page.')
        // No code: the sentence is the tenant's own, and a translation table
        // has nothing to add to it.
        ->and($note->meta['info'] ?? null)->toBeNull()
        // Never handed to the channel.
        ->and($note->sender_type)->toBe(SenderType::Outgoing);

    // Nobody took the conversation, so the flow carries on.
    expect(actionNodeSentBodies($conversation))->toContain('Anything else?');
    expect($conversation->refresh()->status)->toBe(ConversationStatus::Pending);
});

test('an empty note is skipped without leaving a blank line in the thread', function () {
    [$conversation] = actionNodeFixture([
        'type' => ActionNodes::INTERNAL_NOTE,
        'parameters' => ['note' => '   '],
    ]);

    (new FlowExecutor)->startFlow($conversation);

    expect(Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::Info)->count())->toBe(0)
        ->and(actionNodeSentBodies($conversation))->toContain('Anything else?');
});

test('an action node whose author never picked an action is a pass-through', function () {
    // The state a node is in for the seconds between landing on the canvas and
    // its author choosing something — auto-save fires in that gap.
    [$conversation] = actionNodeFixture([
        'type' => null,
        'parameters' => [],
    ]);

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->needs_human)->toBeFalsy()
        ->and(actionNodeSentBodies($conversation))->toContain('Anything else?');
});
