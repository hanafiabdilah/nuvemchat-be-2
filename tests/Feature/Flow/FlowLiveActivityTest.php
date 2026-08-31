<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Events\ConversationActivity;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Live\LiveActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    // Message chains and AI turns run from the queue. Faking it is what lets a
    // test observe the "waiting" phases instead of the sync driver blowing
    // straight through them.
    Queue::fake();
    Event::fake([ConversationActivity::class]);
});

/**
 * start → condition → message (two bubbles, the second after a pause) → response.
 *
 * Deliberately mixes a node that finishes in microseconds with two that have
 * real duration: the panel's status line only ever shows the latter, but the
 * trail is built from every one of them.
 */
function liveActivityFixture(array $responseData = []): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Triagem']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $condition = $flow->nodes()->create([
        'type' => NodeType::Condition,
        'data' => ['field' => 'contact.name', 'operator' => 'is_not_empty', 'value' => '', 'label' => 'Tem nome?'],
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $message = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => [
            'wait_for_reply' => false,
            'messages' => [
                ['body' => 'Olá!', 'message_type' => 'text', 'delay' => 0],
                ['body' => 'Um momento…', 'message_type' => 'text', 'delay' => 5],
            ],
        ],
        'position_x' => 200,
        'position_y' => 0,
    ]);
    $response = $flow->nodes()->create([
        'type' => NodeType::Response,
        'data' => array_merge([
            'body' => 'Qual é o seu CPF?',
            'message_type' => 'text',
            'variable_key' => 'cpf',
            'validation' => 'any',
        ], $responseData),
        'position_x' => 300,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $condition->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $condition->id, 'target_node_id' => $message->id, 'condition_value' => 'true']);
    FlowEdge::create(['source_node_id' => $message->id, 'target_node_id' => $response->id, 'condition_value' => null]);

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

    return compact('conversation', 'connection', 'start', 'condition', 'message', 'response');
}

/**
 * @return array<int, array<string, mixed>>  every activity payload, in order
 */
function activityPayloads(): array
{
    $out = [];

    foreach (Event::dispatched(ConversationActivity::class) as $dispatched) {
        $out[] = $dispatched[0]->broadcastWith();
    }

    return $out;
}

function phasesEmitted(): array
{
    return array_column(activityPayloads(), 'phase');
}

it('announces every node it enters, instant ones included', function () {
    $fixture = liveActivityFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);

    $nodeEvents = array_values(array_filter(
        activityPayloads(),
        fn ($p) => $p['phase'] === LiveActivity::FLOW_NODE
    ));

    $types = array_column(array_column($nodeEvents, 'node'), 'type');

    // The Condition is the point: it resolves in microseconds and would be
    // invisible without this, which is exactly when a flow takes a branch
    // nobody expected and nobody can see why.
    expect($types)->toContain('start', 'condition', 'message');

    $condition = collect($nodeEvents)->firstWhere('node.type', 'condition');
    expect($condition['node']['label'])->toBe('Tem nome?');
});

it('names the pause between message bubbles, with when it ends', function () {
    $fixture = liveActivityFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);

    $delay = collect(activityPayloads())->firstWhere('phase', LiveActivity::FLOW_DELAY);

    expect($delay)->not->toBeNull();
    expect($delay['detail']['seconds'])->toBe(0);
    expect($delay['detail']['index'])->toBe(1);
    expect($delay['detail']['total'])->toBe(2);
    expect($delay['detail']['resume_at'])->toBeGreaterThanOrEqual(now()->timestamp);
});

it('announces a response node as waiting, with its deadline and variable', function () {
    $fixture = liveActivityFixture(['timeout_seconds' => 120]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    // The message node's second bubble is queued, so drive the chain to its end
    // to reach the response node.
    $flowState = $fixture['conversation']->flowState()->first()
        ?? App\Models\FlowState::where('conversation_id', $fixture['conversation']->id)->first();

    $token = $flowState->state_data['_message_chain_'.$fixture['message']->id]['token'];
    (new FlowExecutor)->runScheduledMessageItem($flowState->id, $fixture['message']->id, 1, $token);

    $awaiting = collect(activityPayloads())->firstWhere('phase', LiveActivity::FLOW_AWAITING);

    expect($awaiting)->not->toBeNull();
    expect($awaiting['node']['type'])->toBe('response');
    expect($awaiting['detail']['variable_key'])->toBe('cpf');
    expect($awaiting['detail']['timeout_at'])->toBeGreaterThan(now()->timestamp);
    // The ttl has to outlive the deadline it is showing, or the countdown
    // vanishes before it reaches zero.
    expect($awaiting['ttl'])->toBeGreaterThan(120);
});

it('carries no message content — metadata only', function () {
    $fixture = liveActivityFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);

    $encoded = json_encode(activityPayloads());

    // Every agent on the connection reads these, so the rule LiveMonitor holds
    // itself to holds here: what the flow *said* is not part of what it is
    // *doing*. Node `data` in particular must never be spread into the payload.
    expect($encoded)->not->toContain('Olá!');
    expect($encoded)->not->toContain('Qual é o seu CPF?');
    expect($encoded)->not->toContain('Um momento');
});

it('clears the slot when a human takes the thread', function () {
    $fixture = liveActivityFixture();
    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $executor->stopFlow($fixture['conversation']->fresh());

    expect(phasesEmitted())->toContain(LiveActivity::IDLE);
});

it('says nothing at all when the kill switch is off', function () {
    config(['live.activity_enabled' => false]);

    $fixture = liveActivityFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);

    Event::assertNotDispatched(ConversationActivity::class);
});

it('broadcasts on the connection channel, not the tenant one', function () {
    $fixture = liveActivityFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);

    $event = Event::dispatched(ConversationActivity::class)->first()[0];
    $connection = $fixture['connection'];

    expect($event->broadcastAs())->toBe('conversation-activity');
    expect($event->broadcastOn()[0]->name)
        ->toBe("private-tenant.{$connection->tenant_id}.connection.{$connection->id}");
});
