<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RefreshFlowPresence;
use App\Jobs\RunFlowIntervalNode;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\IntervalNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    // The node is nothing but waiting, and the sync driver cannot wait: it
    // would run every timer the instant it was armed.
    Queue::fake();
});

/** A workspace whose WhatsApp connection runs `$flow`, and one pending conversation on it. */
function intervalWorkspace(): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Intervalo']);

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
        'external_id' => '5511988887777',
        'name' => 'Bia',
        'username' => '5511988887777',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511988887777',
        'status' => ConversationStatus::Pending,
    ]);

    return [$flow, $conversation];
}

function intervalNodeOn(Flow $flow, NodeType $type, ?array $data, int $x = 0): FlowNode
{
    return $flow->nodes()->create(['type' => $type, 'data' => $data, 'position_x' => $x, 'position_y' => 0]);
}

/**
 * start → interval → "Ainda estou aqui".
 *
 * The message after it is how a test tells "the wait ended" from "the wait is
 * still running" without reading state keys.
 */
function intervalFixture(array $data = [], bool $wireNext = true): array
{
    [$flow, $conversation] = intervalWorkspace();

    $start = intervalNodeOn($flow, NodeType::Start, null);
    $interval = intervalNodeOn($flow, NodeType::Interval, array_merge(IntervalNodes::defaults(), $data), 300);
    $after = intervalNodeOn($flow, NodeType::Message, [
        'messages' => [['message_type' => 'text', 'body' => 'Ainda estou aqui', 'delay' => 0]],
    ], 600);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $interval->id]);

    if ($wireNext) {
        FlowEdge::create(['source_node_id' => $interval->id, 'target_node_id' => $after->id]);
    }

    (new FlowExecutor)->startFlow($conversation, $flow);

    return [$flow, $conversation->refresh(), $interval, FlowState::where('conversation_id', $conversation->id)->first()];
}

/**
 * What the customer actually received. Info notes are Outgoing rows too — and
 * one of these tests flips the conversation's status, which writes one — so
 * they are excluded rather than asserted around.
 */
function intervalOutgoing(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

it('parks the flow on the node and queues the moment it ends', function () {
    [, $conversation, $interval, $state] = intervalFixture(['seconds' => 45]);

    $state->refresh();

    expect($state->current_node_id)->toBe($interval->id)
        ->and($state->status)->toBe(FlowStateStatus::Running)
        // Nothing has been sent: the node after it has not run.
        ->and(intervalOutgoing($conversation))->toBe([]);

    $claim = $state->state_data[IntervalNodes::claimKey($interval->id)] ?? null;

    expect($claim)->toBeArray()
        ->and($claim['token'])->toBeString()
        ->and($claim['resume_at'])->toBe(now()->addSeconds(45)->timestamp);

    Queue::assertPushed(RunFlowIntervalNode::class, fn ($job) => $job->nodeId === $interval->id
        && $job->token === $claim['token']);
});

it('moves on when the wait elapses', function () {
    [, $conversation, $interval, $state] = intervalFixture(['seconds' => 30]);

    $token = $state->refresh()->state_data[IntervalNodes::claimKey($interval->id)]['token'];

    (new FlowExecutor)->runIntervalElapsed($state->id, $interval->id, $token);

    expect(intervalOutgoing($conversation))->toBe(['Ainda estou aqui']);

    // The claim is gone, so a flow that comes back round to this node waits
    // again rather than finding it already occupied.
    expect($state->refresh()->state_data)
        ->not->toHaveKey(IntervalNodes::claimKey($interval->id));
});

it('ignores a job whose token has been superseded', function () {
    [, $conversation, $interval, $state] = intervalFixture(['seconds' => 30]);

    (new FlowExecutor)->runIntervalElapsed($state->id, $interval->id, 'some-other-token');

    expect(intervalOutgoing($conversation))->toBe([]);
    expect($state->refresh()->state_data)->toHaveKey(IntervalNodes::claimKey($interval->id));
});

it('does not end the wait when the customer writes', function () {
    [, $conversation, $interval, $state] = intervalFixture(['seconds' => 60]);

    $before = $state->refresh()->state_data[IntervalNodes::claimKey($interval->id)];

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi? já acabou?',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'oi? já acabou?');

    // The clock ends this wait, not the customer — and the wait must not have
    // been restarted either, or somebody who writes twice never reaches the end.
    expect(intervalOutgoing($conversation))->toBe([])
        ->and($state->refresh()->state_data[IntervalNodes::claimKey($interval->id)])->toBe($before);

    Queue::assertPushed(RunFlowIntervalNode::class, 1);
});

it('stops when a person has taken the conversation off the bot', function () {
    [, $conversation, $interval, $state] = intervalFixture(['seconds' => 30]);

    $token = $state->refresh()->state_data[IntervalNodes::claimKey($interval->id)]['token'];

    $conversation->forceFill(['status' => ConversationStatus::Active])->save();

    (new FlowExecutor)->runIntervalElapsed($state->id, $interval->id, $token);

    expect(intervalOutgoing($conversation))->toBe([])
        ->and($state->refresh()->state_data)->not->toHaveKey(IntervalNodes::claimKey($interval->id));
});

it('steps straight over a node that waits for nothing', function () {
    [, $conversation, , $state] = intervalFixture(['seconds' => 0]);

    // Queueing a job that would come straight back is worse than not queueing
    // one, and an unfinished node must not stall the flow.
    expect(intervalOutgoing($conversation))->toBe(['Ainda estou aqui']);

    Queue::assertNotPushed(RunFlowIntervalNode::class);
});

it('completes the flow when nothing follows the wait', function () {
    [, $conversation, $interval, $state] = intervalFixture(['seconds' => 10], wireNext: false);

    $token = $state->refresh()->state_data[IntervalNodes::claimKey($interval->id)]['token'];

    (new FlowExecutor)->runIntervalElapsed($state->id, $interval->id, $token);

    expect($state->refresh()->status)->toBe(FlowStateStatus::Completed);
});

it('caps the wait at a day', function () {
    expect(IntervalNodes::seconds(['seconds' => 999999]))->toBe(IntervalNodes::MAX_SECONDS)
        ->and(IntervalNodes::seconds(['seconds' => -5]))->toBe(0)
        // 24h, because WhatsApp refuses free-form content past it: a longer
        // wait would resume into a send the platform rejects.
        ->and(IntervalNodes::MAX_SECONDS)->toBe(86400);
});

it('says nothing to the customer unless the author asked it to', function () {
    intervalFixture(['seconds' => 20]);

    // An interval is usually room to read or go and look something up. Typing
    // at somebody through that is hurrying them.
    Queue::assertNotPushed(RefreshFlowPresence::class);
});

it('fills the wait when the author asks it to', function () {
    [, , $interval, $state] = intervalFixture(['seconds' => 20, 'presence' => true]);

    $token = $state->refresh()->state_data[IntervalNodes::claimKey($interval->id)]['token'];

    Queue::assertPushed(RefreshFlowPresence::class, fn ($job) => $job->token === $token
        && $job->claimKey === IntervalNodes::claimKey($interval->id)
        && $job->kind === 'typing');
});
