<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\SenderType;
use App\Jobs\RunFlowMessageNode;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]));

/**
 * start → message(under test).
 *
 * The node is deliberately the last one: what this file is about is what
 * reaches the customer and in which order, not where the flow goes afterwards.
 *
 * @param  array<string, mixed>  $data
 */
function messageSequenceFixture(array $data): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Sequence']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $message = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => $data,
        'position_x' => 100,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $message->id, 'condition_value' => null]);

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

    return compact('conversation', 'message', 'flow');
}

/** @return array<int, string> */
function sentBodies(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

it('sends every bubble of a multi-message node, in order', function () {
    $fixture = messageSequenceFixture([
        'wait_for_reply' => false,
        'messages' => [
            ['message_type' => 'text', 'body' => 'Oi!'],
            ['message_type' => 'text', 'body' => 'Tudo bem?'],
            ['message_type' => 'text', 'body' => 'Como posso ajudar?'],
        ],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    expect(sentBodies($fixture['conversation']))->toBe(['Oi!', 'Tudo bem?', 'Como posso ajudar?']);
});

it('still sends a node saved in the old single-message shape', function () {
    $fixture = messageSequenceFixture([
        'body' => 'Bem-vindo!',
        'message_type' => 'text',
        'wait_for_reply' => false,
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    expect(sentBodies($fixture['conversation']))->toBe(['Bem-vindo!']);
});

it('skips bubbles with nothing in them rather than sending blanks', function () {
    $fixture = messageSequenceFixture([
        'wait_for_reply' => false,
        'messages' => [
            ['message_type' => 'text', 'body' => 'Oi!'],
            ['message_type' => 'text', 'body' => '   '],
            ['message_type' => 'text', 'body' => 'Tchau!'],
        ],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    expect(sentBodies($fixture['conversation']))->toBe(['Oi!', 'Tchau!']);
});

it('runs a node with no pauses inline, without touching the queue', function () {
    Queue::fake();

    $fixture = messageSequenceFixture([
        'wait_for_reply' => false,
        'messages' => [
            ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 0],
            ['message_type' => 'text', 'body' => 'Tudo bem?', 'delay' => 0],
        ],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    Queue::assertNotPushed(RunFlowMessageNode::class);
    expect(sentBodies($fixture['conversation']))->toBe(['Oi!', 'Tudo bem?']);
});

it('hands a paused sequence to the queue instead of sleeping in the request', function () {
    Queue::fake();

    $fixture = messageSequenceFixture([
        'wait_for_reply' => false,
        'messages' => [
            ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 0],
            ['message_type' => 'text', 'body' => 'Tudo bem?', 'delay' => 5],
        ],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    // Nothing sent yet: the whole sequence, first bubble included, belongs to
    // the chain — otherwise the inline send and the job would race over order.
    expect(sentBodies($fixture['conversation']))->toBe([]);
    Queue::assertPushed(RunFlowMessageNode::class, fn ($job) => $job->index === 0);
});

it('completes a paused sequence in order once the queue runs it', function () {
    // The sync driver ignores the delay and runs each link as it is dispatched,
    // which is exactly the chain walking itself forward.
    $fixture = messageSequenceFixture([
        'wait_for_reply' => false,
        'messages' => [
            ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 2],
            ['message_type' => 'text', 'body' => 'Tudo bem?', 'delay' => 3],
            ['message_type' => 'text', 'body' => 'Como posso ajudar?', 'delay' => 1],
        ],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    expect(sentBodies($fixture['conversation']))->toBe(['Oi!', 'Tudo bem?', 'Como posso ajudar?']);

    // The chain cleaned up after itself: a node still marked busy would never
    // send again if the flow looped back to it.
    $state = FlowState::where('conversation_id', $fixture['conversation']->id)->first();
    expect($state->state_data)->not->toHaveKey('_message_chain_' . $fixture['message']->id);
});

it('does not restart a sequence that is already in flight', function () {
    Queue::fake();

    $fixture = messageSequenceFixture([
        'wait_for_reply' => false,
        'messages' => [
            ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 4],
            ['message_type' => 'text', 'body' => 'Tudo bem?', 'delay' => 4],
        ],
    ]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    // The customer writes while the sequence is still going out. Re-entering
    // the node here would send the whole thing a second time.
    $executor->resumeFlow($fixture['conversation']->fresh(), 'oi');

    Queue::assertPushed(RunFlowMessageNode::class, 1);
});
