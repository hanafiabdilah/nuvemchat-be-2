<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunFlowCallNode;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\CallNodes;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowExecutor;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.apiway.calls' => true]);

    // The node waits for the call to end, and the sync driver cannot wait: it
    // would run the backstop timer the instant it was armed.
    Queue::fake();
});

/** A core that accepts the call, and every send after it. */
function callNodeCore(array $callResponse = ['success' => true, 'data' => ['callId' => 'CALL-9', 'maxDurationSeconds' => 8]], int $status = 200): void
{
    Http::fake([
        '*/v1/call/make*' => Http::response($callResponse, $status),
        '*/v1/message/*' => Http::response(['success' => true, 'data' => ['Id' => 'MSG-1']]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
    ]);
}

/**
 * start → call → "acabei de te ligar" message, on a connection of $channel.
 *
 * @return array{0: Conversation, 1: array<string, \App\Models\FlowNode>, 2: Connection}
 */
function callNodeFixture(array $data = [], Channel $channel = Channel::WhatsappApiway): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Ligação']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $call = $flow->nodes()->create([
        'type' => NodeType::Call,
        'data' => array_replace(CallNodes::defaults(), $data),
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $after = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Acabei de te ligar!', 'message_type' => 'text'],
        'position_x' => 200,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $call->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $call->id, 'target_node_id' => $after->id, 'condition_value' => null]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => $channel->value,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => $channel === Channel::WhatsappOfficial
            ? ['phone_number_id' => '111000111', 'access_token' => 'wa-token', 'business_account_id' => '222000222']
            : ['token' => 'apiway-token', 'instance_id' => 'inst-1'],
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

    FlowState::create([
        'conversation_id' => $conversation->id,
        'flow_id' => $flow->id,
        'current_node_id' => $start->id,
        'state_data' => ['audio' => 'https://cdn.example.com/oi-ana.mp3'],
    ]);

    return [$conversation, compact('start', 'call', 'after'), $connection];
}

function runCallNodeFlow(Conversation $conversation, array $nodes): void
{
    $state = FlowState::where('conversation_id', $conversation->id)->firstOrFail();

    (fn () => $this->executeFromNode($state, $nodes['start']))->call(new FlowExecutor);
}

/** The texts the flow sent after the call. */
function callNodeTexts(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Text)
        ->pluck('body')
        ->all();
}

function callNodeNote(Conversation $conversation): ?Message
{
    return Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->where('external_id', 'like', 'call:%')
        ->first();
}

function callNodeState(Conversation $conversation): FlowState
{
    return FlowState::where('conversation_id', $conversation->id)->firstOrFail();
}

test('the call goes to the core and the flow waits for it to end', function () {
    callNodeCore();
    [$conversation, $nodes] = callNodeFixture(['ring_seconds' => 8, 'audio_url' => '{{audio}}']);

    runCallNodeFlow($conversation, $nodes);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/call/make?instanceId=inst-1')
        && $request->hasHeader('Authorization', 'Bearer apiway-token')
        && $request['phone'] === '5511999999999'
        && $request['ringSeconds'] === 8
        && $request['audioUrl'] === 'https://cdn.example.com/oi-ana.mp3');

    $note = callNodeNote($conversation);
    $state = callNodeState($conversation);

    expect($note->external_id)->toBe('call:CALL-9')
        ->and($note->meta['info']['code'])->toBe('call_out_ringing')
        ->and($note->meta['call']['direction'])->toBe('outgoing')
        ->and(callNodeTexts($conversation))->toBe([])
        ->and($state->current_node_id)->toBe($nodes['call']->id)
        ->and($state->state_data[CallNodes::VARIABLE])->toBe(CallNodes::STATUS_RINGING)
        ->and($state->state_data[CallNodes::claimKey($nodes['call']->id)]['call_id'])->toBe('CALL-9');

    Queue::assertPushed(RunFlowCallNode::class, fn ($job) => $job->nodeId === $nodes['call']->id);
});

test('the core\'s result ends the wait with the outcome, and the timer then does nothing', function () {
    callNodeCore();
    [$conversation, $nodes, $connection] = callNodeFixture();
    runCallNodeFlow($conversation, $nodes);
    $token = callNodeState($conversation)->state_data[CallNodes::claimKey($nodes['call']->id)]['token'];

    (new WhatsappApiwayHandler)->handle($connection, [
        'type' => 'CallResult',
        'event' => ['CallID' => 'CALL-9', 'Status' => 'answered', 'TalkSeconds' => 12, 'AudioPlayed' => true],
    ]);

    $note = callNodeNote($conversation);

    expect($note->meta['info']['code'])->toBe('call_out_answered')
        ->and($note->meta['info']['params']['seconds'])->toBe(12)
        ->and($note->meta['call']['audio_played'])->toBeTrue()
        ->and(callNodeTexts($conversation))->toBe(['Acabei de te ligar!'])
        ->and(callNodeState($conversation)->state_data[CallNodes::VARIABLE])->toBe(CallNodes::STATUS_ANSWERED);

    (new FlowExecutor)->runCallFinished(callNodeState($conversation)->id, $nodes['call']->id, $token);

    expect(callNodeTexts($conversation))->toBe(['Acabei de te ligar!']);
});

test('with no result the timer moves on and says the outcome is unknown', function () {
    callNodeCore();
    [$conversation, $nodes, $connection] = callNodeFixture();
    runCallNodeFlow($conversation, $nodes);
    $state = callNodeState($conversation);
    $token = $state->state_data[CallNodes::claimKey($nodes['call']->id)]['token'];

    (new RunFlowCallNode($state->id, $nodes['call']->id, $token))->handle();

    expect(callNodeTexts($conversation))->toBe(['Acabei de te ligar!'])
        ->and(callNodeState($conversation)->state_data[CallNodes::VARIABLE])->toBe(CallNodes::STATUS_UNKNOWN);

    // A result that arrives late still finishes the note, and moves nothing.
    (new WhatsappApiwayHandler)->handle($connection, [
        'type' => 'CallResult',
        'event' => ['CallID' => 'CALL-9', 'Status' => 'no_answer'],
    ]);

    expect(callNodeNote($conversation)->meta['info']['code'])->toBe('call_out_missed')
        ->and(callNodeTexts($conversation))->toBe(['Acabei de te ligar!']);
});

test('whatsmeow\'s own events for the flow\'s call are not read as the customer ringing', function () {
    callNodeCore();
    [$conversation, $nodes, $connection] = callNodeFixture();
    runCallNodeFlow($conversation, $nodes);

    (new WhatsappApiwayHandler)->handle($connection, [
        'type' => 'CallReject',
        'event' => [
            'From' => '5511999999999@s.whatsapp.net',
            'CallCreator' => '5511999990000@s.whatsapp.net',
            'CallID' => 'CALL-9',
            'Timestamp' => '2026-11-03T10:00:05-03:00',
        ],
    ]);

    expect(callNodeNote($conversation)->meta['info']['code'])->toBe('call_out_declined')
        ->and(Message::where('message_type', MessageType::Info)->count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(callNodeTexts($conversation))->toBe(['Acabei de te ligar!'])
        ->and(callNodeState($conversation)->state_data[CallNodes::VARIABLE])->toBe(CallNodes::STATUS_REJECTED);
});

test('a recording the core cannot play still leaves the call', function () {
    Http::fake([
        '*/v1/call/make*' => Http::sequence()
            ->push(['error' => 'call_audio_not_supported', 'message' => 'audio calls are not available yet'], 422)
            ->push(['success' => true, 'data' => ['callId' => 'CALL-10']]),
        '*/v1/message/*' => Http::response(['success' => true, 'data' => ['Id' => 'MSG-1']]),
    ]);
    [$conversation, $nodes] = callNodeFixture(['audio_url' => 'https://cdn.example.com/oi.mp3']);

    runCallNodeFlow($conversation, $nodes);

    $calls = Http::recorded(fn (Request $request) => str_contains($request->url(), '/v1/call/make'));

    expect($calls)->toHaveCount(2)
        ->and($calls[0][0]['audioUrl'])->toBe('https://cdn.example.com/oi.mp3')
        ->and(isset($calls[1][0]['audioUrl']))->toBeFalse()
        ->and(callNodeNote($conversation)->external_id)->toBe('call:CALL-10')
        ->and(callNodeNote($conversation)->meta['call'])->not->toHaveKey('audio');
});

test('a call the core refuses leaves a note and the flow carries on', function () {
    callNodeCore(['error' => 'instance_not_connected', 'message' => 'not connected'], 409);
    [$conversation, $nodes] = callNodeFixture();

    runCallNodeFlow($conversation, $nodes);

    $note = Message::where('conversation_id', $conversation->id)->where('message_type', MessageType::Info)->first();

    expect($note->meta['info']['code'])->toStartWith('flow_call_failed')
        ->and(callNodeNote($conversation))->toBeNull()
        ->and(callNodeTexts($conversation))->toBe(['Acabei de te ligar!'])
        ->and(callNodeState($conversation)->state_data[CallNodes::VARIABLE])->toBe(CallNodes::STATUS_FAILED);

    Queue::assertNotPushed(RunFlowCallNode::class);
});

test('a connection that cannot call steps over the node without asking anyone', function (Channel $channel, bool $switchedOn) {
    config(['services.apiway.calls' => $switchedOn]);
    callNodeCore();
    [$conversation, $nodes] = callNodeFixture([], $channel);

    runCallNodeFlow($conversation, $nodes);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/call/make'));

    expect(callNodeTexts($conversation))->toBe(['Acabei de te ligar!'])
        ->and(callNodeState($conversation)->state_data[CallNodes::VARIABLE])->toBe(CallNodes::STATUS_UNAVAILABLE);
})->with([
    'WhatsApp Official' => [Channel::WhatsappOfficial, true],
    'API Way with calls switched off' => [Channel::WhatsappApiway, false],
]);

test('the customer writing during the call does not move the flow', function () {
    callNodeCore();
    [$conversation, $nodes] = callNodeFixture();
    runCallNodeFlow($conversation, $nodes);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'alô?');

    expect(callNodeTexts($conversation))->toBe([])
        ->and(callNodeState($conversation)->current_node_id)->toBe($nodes['call']->id);

    Http::assertSentCount(1);
});

test('resuming a flow paused on the call never rings the customer again', function () {
    callNodeCore();
    [$conversation, $nodes] = callNodeFixture();
    runCallNodeFlow($conversation, $nodes);

    $executor = new FlowExecutor;
    $executor->pauseFlow($conversation->fresh());
    $executor->resumePausedFlow($conversation->fresh());

    $calls = Http::recorded(fn (Request $request) => str_contains($request->url(), '/v1/call/make'));

    expect($calls)->toHaveCount(1)
        ->and(callNodeTexts($conversation))->toBe(['Acabei de te ligar!'])
        // The message after the call is the last node.
        ->and(callNodeState($conversation)->status)->toBe(FlowStateStatus::Completed)
        ->and(callNodeState($conversation)->state_data[CallNodes::VARIABLE])->toBe(CallNodes::STATUS_UNKNOWN);
});

test('saving checks the ring time, and the assistant is told about the node', function () {
    $nodes = [
        ['key' => '1', 'type' => 'start', 'data' => null],
        ['key' => '2', 'type' => 'call', 'data' => ['ring_seconds' => 20, 'audio_url' => null]],
    ];

    expect(fn () => FlowBlueprint::validateNodes($nodes))->toThrow(\Illuminate\Validation\ValidationException::class);

    $nodes[1]['data']['ring_seconds'] = 8;

    expect(fn () => FlowBlueprint::validateNodes($nodes))->not->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(FlowBlueprint::specification())->toContain('### call');
});
