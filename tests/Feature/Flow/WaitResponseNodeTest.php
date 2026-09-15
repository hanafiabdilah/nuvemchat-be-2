<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunFlowWaitResponseBuffer;
use App\Jobs\RunFlowWaitResponseTimeout;
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
use App\Services\Flow\WaitResponseNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    // The node is mostly waiting, and the sync driver cannot wait: it would run
    // every timer and burst window the instant it was armed. Faking the queue
    // is what lets a test say "now the silence ran out" and mean it.
    Queue::fake();
});

/** A workspace whose WhatsApp connection runs `$flow`, and one pending conversation on it. */
function waitWorkspace(): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Espera']);

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

    return [$flow, $conversation];
}

function waitNode(Flow $flow, NodeType $type, ?array $data, int $x = 0, int $y = 0): FlowNode
{
    return $flow->nodes()->create(['type' => $type, 'data' => $data, 'position_x' => $x, 'position_y' => $y]);
}

function waitEdge(FlowNode $from, FlowNode $to, ?string $value = null): void
{
    FlowEdge::create(['source_node_id' => $from->id, 'target_node_id' => $to->id, 'condition_value' => $value]);
}

function waitBubble(string $body): array
{
    return ['messages' => [['message_type' => 'text', 'body' => $body]]];
}

/**
 * start → wait → (replied) "Obrigado!" · (timeout) "Ainda está aí?".
 *
 * Both branches end in a message so a test can tell which one ran by reading
 * what the customer received.
 */
function waitFixture(array $data = [], bool $wireTimeout = true, bool $wireReplied = true): array
{
    [$flow, $conversation] = waitWorkspace();

    $start = waitNode($flow, NodeType::Start, null);
    $wait = waitNode($flow, NodeType::WaitResponse, array_merge([
        'message' => 'Qual é o seu nome?',
        'variable_key' => 'nome',
    ], $data), 300);
    $thanks = waitNode($flow, NodeType::Message, waitBubble('Obrigado!'), 600);
    $nudge = waitNode($flow, NodeType::Message, waitBubble('Ainda está aí?'), 600, 200);

    waitEdge($start, $wait);

    if ($wireReplied) {
        waitEdge($wait, $thanks, WaitResponseNodes::BRANCH_REPLIED);
    }

    if ($wireTimeout) {
        waitEdge($wait, $nudge, WaitResponseNodes::BRANCH_TIMEOUT);
    }

    // The webhook stores the customer's opening message before it starts the
    // flow — the one message a wait must never read back as its reply.
    waitCustomerSays($conversation, 'olá');

    return compact('conversation', 'wait', 'flow');
}

function waitCustomerSays(Conversation $conversation, string $body): Message
{
    return Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);
}

/** The customer writes, and the webhook hands it to the flow. */
function waitCustomerWrites(Conversation $conversation, string $body): void
{
    waitCustomerSays($conversation, $body);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), $body);
}

/** What the customer received. Info notes are Outgoing too, and never leave the panel. */
function waitOutgoing(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

function waitState(Conversation $conversation): FlowState
{
    return FlowState::where('conversation_id', $conversation->id)->firstOrFail();
}

// ───────────────────────────── Waiting ─────────────────────────────

it('sends its message and waits, without reading the opening message as the reply', function () {
    $fixture = waitFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);

    $state = waitState($fixture['conversation']);

    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu nome?'])
        ->and($state->state_data)->toHaveKey(WaitResponseNodes::parkedKey($fixture['wait']->id))
        ->and($state->state_data)->not->toHaveKey('nome')
        ->and($state->current_node_id)->toBe($fixture['wait']->id);

    // No limit, so nothing is ever going to give up on the customer.
    Queue::assertNotPushed(RunFlowWaitResponseTimeout::class);
});

it('waits without saying anything when it has no message', function () {
    $fixture = waitFixture(['message' => '']);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    expect(waitOutgoing($fixture['conversation']))->toBe([])
        ->and(waitState($fixture['conversation'])->state_data)->toHaveKey(WaitResponseNodes::parkedKey($fixture['wait']->id));
});

it('stores the reply and takes the replied branch', function () {
    $fixture = waitFixture();

    (new FlowExecutor)->startFlow($fixture['conversation']);
    waitCustomerWrites($fixture['conversation'], 'Ana');

    $state = waitState($fixture['conversation']);

    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Obrigado!'])
        ->and($state->state_data['nome'] ?? null)->toBe('Ana')
        ->and($state->state_data)->not->toHaveKey(WaitResponseNodes::parkedKey($fixture['wait']->id));
});

it('finishes the flow when nothing is wired after the reply', function () {
    $fixture = waitFixture(wireReplied: false);

    (new FlowExecutor)->startFlow($fixture['conversation']);
    waitCustomerWrites($fixture['conversation'], 'Ana');

    // Left running on the node, the next message would be read as another
    // reply to a wait that already ended.
    expect(waitState($fixture['conversation'])->status)->toBe(FlowStateStatus::Completed);
});

// ───────────────────────────── The limit ─────────────────────────────

it('takes the timeout branch when the customer stays silent', function () {
    $fixture = waitFixture(['timeout_seconds' => 120]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    Queue::assertPushed(RunFlowWaitResponseTimeout::class);

    $state = waitState($fixture['conversation']);
    $token = $state->state_data[WaitResponseNodes::timeoutKey($fixture['wait']->id)];

    $executor->runWaitResponseTimeout($state->id, $fixture['wait']->id, $token);

    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Ainda está aí?']);
});

it('accepts a limit measured in weeks', function () {
    $fixture = waitFixture(['timeout_seconds' => 21 * 86400, 'timeout_unit' => 'days']);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    Queue::assertPushed(RunFlowWaitResponseTimeout::class);
});

it('goes on waiting when the timeout branch was never wired', function () {
    $fixture = waitFixture(['timeout_seconds' => 120], wireTimeout: false);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $state = waitState($fixture['conversation']);
    $token = $state->state_data[WaitResponseNodes::timeoutKey($fixture['wait']->id)];

    $executor->runWaitResponseTimeout($state->id, $fixture['wait']->id, $token);

    // Nothing sent, and a later reply is still the reply.
    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu nome?']);

    waitCustomerWrites($fixture['conversation'], 'Ana');

    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Obrigado!']);
});

it('disarms the limit once the customer answers', function () {
    $fixture = waitFixture(['timeout_seconds' => 120]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $state = waitState($fixture['conversation']);
    $token = $state->state_data[WaitResponseNodes::timeoutKey($fixture['wait']->id)];

    waitCustomerWrites($fixture['conversation'], 'Ana');

    // The job is already queued. Running it now must change nothing.
    $executor->runWaitResponseTimeout($state->id, $fixture['wait']->id, $token);

    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu nome?', 'Obrigado!']);
});

it('leaves the flow alone when an agent took the conversation while it waited', function () {
    $fixture = waitFixture(['timeout_seconds' => 120]);

    $executor = new FlowExecutor;
    $executor->startFlow($fixture['conversation']);

    $state = waitState($fixture['conversation']);
    $token = $state->state_data[WaitResponseNodes::timeoutKey($fixture['wait']->id)];

    $fixture['conversation']->update(['status' => ConversationStatus::Active]);

    $executor->runWaitResponseTimeout($state->id, $fixture['wait']->id, $token);

    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu nome?']);
});

// ───────────────────────────── Format ─────────────────────────────

it('holds the reply to its format, sends the error, and waits again from scratch', function () {
    $fixture = waitFixture([
        'message' => 'Qual é o seu e-mail?',
        'variable_key' => 'email',
        'validation' => 'email',
        'error_message' => 'Não consegui ler esse e-mail.',
        'timeout_seconds' => 600,
    ]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    $armed = waitState($fixture['conversation'])->state_data[WaitResponseNodes::timeoutKey($fixture['wait']->id)];

    waitCustomerWrites($fixture['conversation'], 'não sei');

    $state = waitState($fixture['conversation']);

    expect(waitOutgoing($fixture['conversation']))->toBe(['Qual é o seu e-mail?', 'Não consegui ler esse e-mail.'])
        ->and($state->state_data)->not->toHaveKey('email')
        // Someone who answers wrongly is still here: the silence being timed
        // never happened, so the clock starts over.
        ->and($state->state_data[WaitResponseNodes::timeoutKey($fixture['wait']->id)])->not->toBe($armed);

    waitCustomerWrites($fixture['conversation'], ' ana@example.com ');

    expect(collect(waitOutgoing($fixture['conversation']))->last())->toBe('Obrigado!')
        ->and(waitState($fixture['conversation'])->state_data['email'])->toBe('ana@example.com');
});

// ───────────────────────────── Buffer ─────────────────────────────

it('reads a burst of messages as one reply once the customer stops typing', function () {
    $fixture = waitFixture(['message' => 'Como posso ajudar?', 'variable_key' => 'pedido', 'buffer_seconds' => 15]);

    (new FlowExecutor)->startFlow($fixture['conversation']);

    waitCustomerWrites($fixture['conversation'], 'oi');
    waitCustomerWrites($fixture['conversation'], 'tenho uma dúvida');
    waitCustomerWrites($fixture['conversation'], 'sobre o pedido 123');

    // Nothing answered while the customer was still typing.
    expect(waitOutgoing($fixture['conversation']))->toBe(['Como posso ajudar?']);

    $jobs = Queue::pushed(RunFlowWaitResponseBuffer::class)->values();
    expect($jobs)->toHaveCount(3);

    // A window pushed back by a later message steps aside when it wakes.
    $jobs->first()->handle();
    expect(waitOutgoing($fixture['conversation']))->toBe(['Como posso ajudar?']);

    $jobs->last()->handle();

    expect(waitOutgoing($fixture['conversation']))->toBe(['Como posso ajudar?', 'Obrigado!'])
        ->and(waitState($fixture['conversation'])->state_data['pedido'])
        ->toBe("oi\ntenho uma dúvida\nsobre o pedido 123");
});

// ─────────────────── The nodes that used to wait ───────────────────

it('never parks on a message node: the next node runs straight away', function () {
    [$flow, $conversation] = waitWorkspace();

    $start = waitNode($flow, NodeType::Start, null);
    $first = waitNode($flow, NodeType::Message, waitBubble('Oi!'), 300);
    $second = waitNode($flow, NodeType::Message, waitBubble('Nosso horário é das 9h às 18h.'), 600);

    waitEdge($start, $first);
    waitEdge($first, $second);

    (new FlowExecutor)->startFlow($conversation);

    expect(waitOutgoing($conversation))->toBe(['Oi!', 'Nosso horário é das 9h às 18h.'])
        // Finished on the last message rather than left running on it, where the
        // customer's next message would send it again.
        ->and(waitState($conversation)->status)->toBe(FlowStateStatus::Completed);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'ok');

    expect(waitOutgoing($conversation))->toHaveCount(2);
});

it('moves a response node on through its one output once answered', function () {
    [$flow, $conversation] = waitWorkspace();

    $start = waitNode($flow, NodeType::Start, null);
    $ask = waitNode($flow, NodeType::Response, [
        'body' => 'Qual é o seu nome?',
        'message_type' => 'text',
        'variable_key' => 'nome',
        'validation' => 'any',
    ], 300);
    $thanks = waitNode($flow, NodeType::Message, waitBubble('Obrigado!'), 600);

    waitEdge($start, $ask);
    waitEdge($ask, $thanks);

    (new FlowExecutor)->startFlow($conversation);
    waitCustomerWrites($conversation, 'Ana');

    expect(waitOutgoing($conversation))->toBe(['Qual é o seu nome?', 'Obrigado!'])
        ->and(waitState($conversation)->state_data['nome'])->toBe('Ana');
});
