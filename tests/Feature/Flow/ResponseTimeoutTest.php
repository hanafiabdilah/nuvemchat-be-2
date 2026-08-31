<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunFlowResponseTimeout;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\ResponseNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    // The whole point of this node is a delay, and the sync driver has none:
    // it would run the timeout the instant it was armed. Faking the queue is
    // what lets a test say "now the silence ran out" and mean it.
    Queue::fake();
});

/**
 * start → response → (replied) message · (timeout) message.
 *
 * Both branches end in a message node so the test can tell which one ran by
 * reading what the customer received — the same thing the author is deciding
 * between when they wire the two handles.
 *
 * @param  array<string, mixed>  $responseData
 * @param  bool  $wireTimeout  false leaves the no-reply branch unwired, which
 *                             is what every flow saved before it existed looks
 *                             like.
 * @param  ?string  $repliedBranch  the condition_value on the "answered" edge;
 *                                  null reproduces a pre-split flow.
 */
function responseTimeoutFixture(
    array $responseData = [],
    bool $wireTimeout = true,
    ?string $repliedBranch = ResponseNodes::BRANCH_REPLIED,
): array {
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Ask']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $response = $flow->nodes()->create([
        'type' => NodeType::Response,
        'data' => array_merge([
            'body' => 'Qual é o seu nome?',
            'message_type' => 'text',
            'variable_key' => 'nome',
            'validation' => 'any',
        ], $responseData),
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $answered = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Obrigado!', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 0,
    ]);
    $abandoned = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Ainda está aí?', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 100,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $response->id, 'condition_value' => null]);
    FlowEdge::create([
        'source_node_id' => $response->id,
        'target_node_id' => $answered->id,
        'condition_value' => $repliedBranch,
    ]);

    if ($wireTimeout) {
        FlowEdge::create([
            'source_node_id' => $response->id,
            'target_node_id' => $abandoned->id,
            'condition_value' => ResponseNodes::BRANCH_TIMEOUT,
        ]);
    }

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

    return compact('conversation', 'response', 'flow');
}

/**
 * What the customer actually received. Info notes are written Outgoing too —
 * the status-change line the observer adds when an agent takes over — and they
 * never leave the panel.
 *
 * @return array<int, string>
 */
function outgoingBodies(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

function timeoutToken(Conversation $conversation, int $nodeId): ?string
{
    $state = FlowState::where('conversation_id', $conversation->id)->first();

    return $state?->state_data["_response_timeout_{$nodeId}"] ?? null;
}

it('arms a timer when the node has a no-reply limit', function () {
    $fixture = responseTimeoutFixture(['timeout_seconds' => 120]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    Queue::assertPushed(RunFlowResponseTimeout::class);
    expect(timeoutToken($fixture['conversation'], $fixture['response']->id))->not->toBeNull();
});

it('arms nothing when the node has no limit', function () {
    $fixture = responseTimeoutFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);

    Queue::assertNotPushed(RunFlowResponseTimeout::class);
});

it('takes the no-reply branch when the customer stays silent', function () {
    $fixture = responseTimeoutFixture(['timeout_seconds' => 120]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $token = timeoutToken($fixture['conversation'], $fixture['response']->id);
    $state = FlowState::where('conversation_id', $fixture['conversation']->id)->first();

    $executor->runResponseTimeout($state->id, $fixture['response']->id, $token);

    expect(outgoingBodies($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Ainda está aí?']);
});

it('goes on waiting when the no-reply branch was never wired', function () {
    $fixture = responseTimeoutFixture(['timeout_seconds' => 120], wireTimeout: false);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $token = timeoutToken($fixture['conversation'], $fixture['response']->id);
    $state = FlowState::where('conversation_id', $fixture['conversation']->id)->first();

    $executor->runResponseTimeout($state->id, $fixture['response']->id, $token);

    // Nothing sent, and the node is still holding its question — a later reply
    // must still be treated as the answer, not as a fresh start.
    expect(outgoingBodies($fixture['conversation']))->toBe(['Qual é o seu nome?']);
    expect($state->fresh()->state_data)->toHaveKey("_response_sent_{$fixture['response']->id}");
});

it('disarms the timer once the customer answers', function () {
    $fixture = responseTimeoutFixture(['timeout_seconds' => 120]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $token = timeoutToken($fixture['conversation'], $fixture['response']->id);
    $executor->resumeFlow($fixture['conversation']->fresh(), 'Ana');

    $state = FlowState::where('conversation_id', $fixture['conversation']->id)->first();

    expect(outgoingBodies($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Obrigado!']);
    expect($state->state_data['nome'] ?? null)->toBe('Ana');

    // The job is already queued. Running it now must change nothing.
    $executor->runResponseTimeout($state->id, $fixture['response']->id, $token);

    expect(outgoingBodies($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Obrigado!']);
});

it('restarts the clock when the answer fails validation', function () {
    $fixture = responseTimeoutFixture([
        'timeout_seconds' => 120,
        'validation' => 'number',
        'error_message' => 'Só números, por favor.',
    ]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $armed = timeoutToken($fixture['conversation'], $fixture['response']->id);

    $executor->resumeFlow($fixture['conversation']->fresh(), 'não é número');

    // Someone who answers wrongly is still here; the silence they were being
    // timed for never happened.
    expect(timeoutToken($fixture['conversation'], $fixture['response']->id))
        ->not->toBeNull()
        ->not->toBe($armed);
});

it('follows a pre-split flow whose only edge carries no branch value', function () {
    $fixture = responseTimeoutFixture(repliedBranch: null);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);
    $executor->resumeFlow($fixture['conversation']->fresh(), 'Ana');

    expect(outgoingBodies($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Obrigado!']);
});

it('leaves the flow alone when an agent took the conversation while the clock ran', function () {
    $fixture = responseTimeoutFixture(['timeout_seconds' => 120]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $token = timeoutToken($fixture['conversation'], $fixture['response']->id);
    $state = FlowState::where('conversation_id', $fixture['conversation']->id)->first();

    $fixture['conversation']->update(['status' => ConversationStatus::Active]);

    $executor->runResponseTimeout($state->id, $fixture['response']->id, $token);

    expect(outgoingBodies($fixture['conversation']))->toBe(['Qual é o seu nome?']);
});
