<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
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
use App\Support\Errors\TransportFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * What happens to a Message node when the channel will not take a bubble.
 *
 * The behaviour under test was written after a real incident (27 Sep 2026): a
 * WhatsApp instance was unreachable for about 40 seconds, two bubbles of a
 * four-bubble node were dropped, nothing was recorded anywhere, and the
 * customer received the third one first — so the sequence read as scrambled and
 * the funnel carried on discussing material that never arrived.
 *
 * ⚠️ The helpers here are named for this file on purpose: Pest loads every test
 * file into one process, so a shared name is a fatal redeclare, and borrowing a
 * neighbour's helper breaks this file when it runs alone.
 */

/**
 * start → message(under test) → message(after).
 *
 * The node after it is what proves the important half: a sequence that could
 * not be delivered must not hand the flow on, because everything downstream is
 * written assuming the customer saw what came before.
 *
 * @param  array<int, array<string, mixed>>  $items
 * @return array{conversation: Conversation, node: \App\Models\FlowNode, after: \App\Models\FlowNode}
 */
function deliveryFailureFixture(array $items): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Delivery']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $node = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['messages' => $items],
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $after = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['messages' => [['message_type' => 'text', 'body' => 'AFTER', 'delay' => 0]]],
        'position_x' => 200,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $node->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $node->id, 'target_node_id' => $after->id, 'condition_value' => null]);

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

    return compact('conversation', 'node', 'after');
}

/** Bodies the customer actually received — notes are not sent to anyone. */
function deliveredBodies(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

function deliveryFailureNote(Conversation $conversation): ?Message
{
    return Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->orderBy('id')
        ->get()
        ->first(fn (Message $m) => str_starts_with((string) ($m->meta['info']['code'] ?? ''), 'flow_message_failed'));
}

/**
 * A channel that refuses the first $failures attempts the way an unreachable
 * host does, then answers normally.
 *
 * Returns its own attempt counter, because Http::assertSentCount() cannot see
 * these: the fake throws instead of answering, and Laravel only records a
 * request once it has a response to record with it.
 */
function fakeUnreachableWhatsapp(int $failures): object
{
    $counter = new class
    {
        public int $seen = 0;
    };

    Http::fake(['graph.facebook.com/*' => function () use ($counter, $failures) {
        $counter->seen++;

        if ($counter->seen <= $failures) {
            // cURL's connect-phase wording, verbatim from the production log.
            throw new ConnectionException(
                'cURL error 28: Connection timed out after 10002 milliseconds (see https://curl.se/libcurl/c/libcurl-errors.html)'
            );
        }

        return Http::response(['messages' => [['id' => 'wamid.OUT' . $counter->seen]]]);
    }]);

    return $counter;
}

it('sends a bubble again when the channel was never reached', function () {
    fakeUnreachableWhatsapp(1);

    $fixture = deliveryFailureFixture([
        ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 2],
        ['message_type' => 'text', 'body' => 'Tudo bem?', 'delay' => 2],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    // The retry delivered the bubble that failed, in its place, and the rest of
    // the sequence followed it.
    expect(deliveredBodies($fixture['conversation']))->toBe(['Oi!', 'Tudo bem?', 'AFTER']);
    expect(deliveryFailureNote($fixture['conversation']))->toBeNull();
});

it('keeps the sequence in order when a bubble in the middle has to be retried', function () {
    // The image is the one that fails, and it must not end up behind the text
    // that comes after it — the scrambling in the incident was exactly this.
    $seen = 0;
    Http::fake(['graph.facebook.com/*' => function () use (&$seen) {
        $seen++;

        if ($seen === 2) {
            throw new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds');
        }

        return Http::response(['messages' => [['id' => 'wamid.OUT' . $seen]]]);
    }]);

    $fixture = deliveryFailureFixture([
        ['message_type' => 'text', 'body' => 'first', 'delay' => 1],
        ['message_type' => 'image', 'body' => 'second', 'attachment_url' => 'https://cdn.example.com/a.jpg', 'delay' => 1],
        ['message_type' => 'text', 'body' => 'third', 'delay' => 1],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    expect(deliveredBodies($fixture['conversation']))->toBe(['first', 'second', 'third', 'AFTER']);
});

it('stops the flow and leaves a note when a bubble cannot be delivered at all', function () {
    fakeUnreachableWhatsapp(99);

    $fixture = deliveryFailureFixture([
        ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 2],
        ['message_type' => 'text', 'body' => 'Tudo bem?', 'delay' => 2],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    // Nothing reached the customer, and — the point of the change — the rest of
    // the sequence and the node after it were not sent either.
    expect(deliveredBodies($fixture['conversation']))->toBe([]);

    $note = deliveryFailureNote($fixture['conversation']);
    expect($note)->not->toBeNull();
    expect($note->meta['info']['code'])->toBe('flow_message_failed_reason');
    expect($note->meta['info']['params']['position'])->toBe(1);

    $state = FlowState::where('conversation_id', $fixture['conversation']->id)->first();
    expect($state->status)->toBe(FlowStateStatus::Failed);

    // The chain let go of the node: a claim left behind would block it forever.
    expect($state->state_data ?? [])->not->toHaveKey('_message_chain_' . $fixture['node']->id);
});

it('gives up after the capped number of attempts rather than retrying forever', function () {
    $attempts = fakeUnreachableWhatsapp(99);

    $fixture = deliveryFailureFixture([
        ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 2],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    // Three sends of the one bubble, then it stopped.
    expect($attempts->seen)->toBe(3);
});

it('does not retry a channel that answered, only one that never did', function () {
    // A refusal is an answer: the request arrived, so sending it again is a
    // second bubble in front of the customer for a request that will fail the
    // same way.
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => 'Recipient phone number not in allowed list'],
    ], 400)]);


    $fixture = deliveryFailureFixture([
        ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 2],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    Http::assertSentCount(1);
    expect(deliveredBodies($fixture['conversation']))->toBe([]);
    expect(deliveryFailureNote($fixture['conversation']))->not->toBeNull();

    $state = FlowState::where('conversation_id', $fixture['conversation']->id)->first();
    expect($state->status)->toBe(FlowStateStatus::Failed);
});

it('hands a node with no pauses to the queue rather than retrying inside the webhook', function () {
    Queue::fake();
    fakeUnreachableWhatsapp(99);

    $fixture = deliveryFailureFixture([
        ['message_type' => 'text', 'body' => 'first', 'delay' => 0],
        ['message_type' => 'text', 'body' => 'second', 'delay' => 0],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    // Sleeping here would be sleeping inside the webhook that delivered the
    // customer's message, which is what the channel retries. The retry is a
    // queued job, and it resumes at the bubble that failed — not at the start,
    // which would re-send anything already delivered.
    Queue::assertPushed(RunFlowMessageNode::class, fn ($job) => $job->index === 0 && $job->attempt === 1);
});

it('resumes an inline sequence at the bubble that failed, never re-sending the ones before it', function () {
    Queue::fake();

    $seen = 0;
    Http::fake(['graph.facebook.com/*' => function () use (&$seen) {
        $seen++;

        if ($seen === 2) {
            throw new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds');
        }

        return Http::response(['messages' => [['id' => 'wamid.OUT' . $seen]]]);
    }]);

    $fixture = deliveryFailureFixture([
        ['message_type' => 'text', 'body' => 'first', 'delay' => 0],
        ['message_type' => 'text', 'body' => 'second', 'delay' => 0],
        ['message_type' => 'text', 'body' => 'third', 'delay' => 0],
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    expect(deliveredBodies($fixture['conversation']))->toBe(['first']);
    Queue::assertPushed(RunFlowMessageNode::class, fn ($job) => $job->index === 1 && $job->attempt === 1);
});

it('tells a connect failure apart from one that may already have been delivered', function () {
    // Connect phase: nothing was written to the socket.
    expect(TransportFailure::undelivered(
        new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds')
    ))->toBeTrue();

    expect(TransportFailure::undelivered(
        new ConnectionException('cURL error 7: Failed to connect to whats-api.ipbr.pro port 443: Connection refused')
    ))->toBeTrue();

    expect(TransportFailure::undelivered(
        new ConnectionException('cURL error 6: Could not resolve host: whats-api.ipbr.pro')
    ))->toBeTrue();

    // ⚠️ Read phase. The channel had the request; the answer is what went
    // missing, so it may well have been delivered. Not retriable, and the
    // wording is close enough to the connect timeout that a loose match on
    // "timed out" would wrongly pull it in.
    expect(TransportFailure::undelivered(
        new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')
    ))->toBeFalse();

    expect(TransportFailure::undelivered(new Exception('Recipient phone number not in allowed list')))->toBeFalse();
    expect(TransportFailure::undelivered(null))->toBeFalse();
});

it('finds the cause through the layers each send is wrapped in', function () {
    // This is the shape that actually arrives: the handler rethrows its own
    // sentence, and MessageService::guard() then replaces it with copy for the
    // customer. Only `previous` still knows what went wrong.
    $translated = new Exception(
        'Não foi possível falar com a instância do WhatsApp.',
        0,
        new Exception(
            'Failed to send WhatsApp message',
            0,
            new ConnectionException('cURL error 28: Connection timed out after 10002 milliseconds')
        )
    );

    expect(TransportFailure::undelivered($translated))->toBeTrue();
});
