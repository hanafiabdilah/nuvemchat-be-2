<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RefreshAiTypingIndicator;
use App\Jobs\RunAiAgentTurn;
use App\Jobs\SendAiHoldingMessage;
use App\Models\AiHubAgent;
use App\Models\AiHubTenant;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiConversationContext;
use App\Services\AiAgentHub\AiHoldingMessage;
use App\Services\AiAgentHub\AiTypingPresence;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The lines a node is given for this suite. Two of them, because rotation is
 * part of the promise: a customer who waits twice must not read the same
 * sentence twice.
 */
const HOLD_LINES = [
    'Só um instante, estou verificando isso…',
    'Um momento, já te respondo.',
];

const HOLD_MEDIA_LINES = ['Deixa eu dar uma olhada no que você enviou…'];

/**
 * A conversation parked on an AIAgent node.
 *
 * Named for this file: Pest loads every test file into one process, so a helper
 * sharing a name with a neighbour is a fatal redeclare.
 *
 * @return array{0: Conversation, 1: FlowNode}
 */
function holdFixture(array $nodeData = [], Channel $channel = Channel::WhatsappOfficial): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $hubTenant = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => 'hub-tenant-h1',
        'external_id' => 'Pingly_h1',
        'name' => 'Pingly_h1',
        'status' => 'ACTIVE',
    ]);

    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'hub-agent-h1',
        'external_id' => 'agente_atendimento',
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ]);

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Suporte']);

    $start = $flow->nodes()->create([
        'type' => NodeType::Start,
        'data' => null,
        'position_x' => 0,
        'position_y' => 0,
    ]);

    $ai = $flow->nodes()->create([
        'type' => NodeType::AIAgent,
        'data' => array_merge([
            'ai_hub_agent_id' => $agent->id,
            'welcoming_message' => 'Oi! Como posso ajudar?',
        ], $nodeData),
        'position_x' => 100,
        'position_y' => 0,
    ]);

    FlowEdge::create([
        'source_node_id' => $start->id,
        'target_node_id' => $ai->id,
        'condition_value' => null,
    ]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => [
            'phone_number_id' => '1083508778182246',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'external_id' => '5511977777777',
        'name' => 'Bruno',
        'username' => '5511977777777',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511977777777',
        'status' => ConversationStatus::Pending,
    ]);

    return [$conversation, $ai];
}

/**
 * The channels and the hub, with an optional hook that runs *while the model is
 * thinking*.
 *
 * That hook is the only honest way to test this: the holding message is armed
 * inside the turn and disarmed the moment it ends, so a queued job examined
 * after the run has finished is examining a wait that is already over. Firing
 * it from inside the hub call puts it exactly where a real worker would pick it
 * up — mid-run, with the customer still waiting.
 */
function holdFakeChannels(string $aiReply = 'Claro, o pedido 123 está a caminho.', ?callable $whileThinking = null): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]]),
        'api-ia.ipbr.pro/*' => function () use ($aiReply, $whileThinking) {
            if ($whileThinking) {
                $whileThinking();
            }

            return Http::response([
                'id' => 'run_h1',
                'status' => 'COMPLETED',
                'output' => ['message' => $aiReply, 'handoff' => false],
            ]);
        },
    ]);
}

/** Run the holding job that the turn in flight armed, as a worker would. */
function holdFireArmed(Conversation $conversation): void
{
    $armed = Queue::pushed(SendAiHoldingMessage::class)->last();

    if (! $armed) {
        return;
    }

    (new FlowExecutor)->sendAiHoldingMessage(
        $armed->flowStateId,
        $armed->nodeId,
        $armed->token,
        $armed->afterMessageId,
        $armed->media,
    );
}

/** Past the welcome turn: the opening never reaches the AI. */
function holdOpen(Conversation $conversation): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.H0',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->startFlow($conversation);
}

/** One inbound message, then the webhook's call into the flow. */
function holdIncoming(Conversation $conversation, string $body, MessageType $type = MessageType::Text): Message
{
    $message = $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => $type,
        'body' => $body,
        'attachment' => $type === MessageType::Text ? null : 'media/photo.jpg',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), $body);

    return $message;
}

/** Run every armed turn, oldest first. */
function holdRunTurns(): void
{
    foreach (Queue::pushed(RunAiAgentTurn::class)->all() as $job) {
        $job->handle();
    }
}

/** The bodies the customer actually received, in order. */
function holdOutgoing(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

it('stays silent on a node that was never given any lines', function () {
    holdFakeChannels();
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation] = holdFixture();
    holdOpen($conversation);
    holdIncoming($conversation, 'cadê meu pedido?');
    holdRunTurns();

    // The key is absent on every flow built before this existed, and absent has
    // to mean silent: an engine upgrade must not start putting sentences nobody
    // wrote in front of customers.
    Queue::assertNotPushed(SendAiHoldingMessage::class);
    expect(holdOutgoing($conversation))->toBe(['Oi! Como posso ajudar?', 'Claro, o pedido 123 está a caminho.']);
});

it('arms the line for the moment the customer has waited long enough', function () {
    holdFakeChannels();
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation, $node] = holdFixture([
        'holding_message' => ['messages' => HOLD_LINES, 'after_seconds' => 10],
        'response_delay_seconds' => 4,
    ]);
    holdOpen($conversation);
    $message = holdIncoming($conversation, 'cadê meu pedido?');
    holdRunTurns();

    $armed = Queue::pushed(SendAiHoldingMessage::class)->all();

    expect($armed)->toHaveCount(1)
        ->and($armed[0]->nodeId)->toBe($node->id)
        ->and($armed[0]->afterMessageId)->toBe($message->id)
        ->and($armed[0]->media)->toBeFalse()
        // Counted from the customer's own message, not from when the turn got
        // to run — by then the grouping window has already spent part of it.
        ->and($armed[0]->delay->diffInSeconds(now()))->toBeLessThanOrEqual(10);
});

it('sends the line, flagged so the hub never reads it back', function () {
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation] = holdFixture([
        'holding_message' => ['messages' => HOLD_LINES],
    ]);

    // Fired mid-run, which is the only moment it is ever owed.
    holdFakeChannels(whileThinking: fn () => holdFireArmed($conversation));

    holdOpen($conversation);
    holdIncoming($conversation, 'cadê meu pedido?');
    holdRunTurns();

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->whereIn('body', HOLD_LINES)
        ->first();

    expect($sent)->not->toBeNull()
        ->and($sent->meta[AiHoldingMessage::META_FLAG])->toBeTrue()
        // Attributed to the automation, so it never counts as an agent having
        // answered — the funnel and the response-time stats both read that.
        ->and($sent->sent_by_flow_id)->not->toBeNull()
        ->and($sent->sent_by_user_id)->toBeNull();

    // And it arrives before the answer, not after it.
    expect(holdOutgoing($conversation))->toBe([
        'Oi! Como posso ajudar?',
        $sent->body,
        'Claro, o pedido 123 está a caminho.',
    ]);

    // The whole reason for the flag: everything in the transcript block is
    // delivered to the hub as the customer's own message and scanned there for
    // handoff keywords, and our courtesy is not the conversation's content.
    $input = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Incoming)
        ->latest('id')
        ->get();

    [$context] = AiConversationContext::build($conversation->fresh(), 0, $input);

    expect((string) $context)->not->toContain($sent->body);
});

it('says nothing once the answer has landed', function () {
    holdFakeChannels();
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation] = holdFixture([
        'holding_message' => ['messages' => HOLD_LINES],
    ]);
    holdOpen($conversation);
    holdIncoming($conversation, 'cadê meu pedido?');

    // The model answered while this job was still sitting in the queue — the
    // common case, and the one the whole delay exists to produce.
    holdRunTurns();
    holdFireArmed($conversation);

    // An apology for a delay, arriving under the answer that disproves it, is
    // worse than the silence it was meant to fill.
    expect(holdOutgoing($conversation))->toBe(['Oi! Como posso ajudar?', 'Claro, o pedido 123 está a caminho.']);
});

it('says nothing once a person has taken the conversation', function () {
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation] = holdFixture([
        'holding_message' => ['messages' => HOLD_LINES],
    ]);

    // Somebody pressed "Assumir da IA" mid-run, which is exactly the window
    // that button is offered for.
    holdFakeChannels(whileThinking: function () use ($conversation) {
        $conversation->update(['status' => ConversationStatus::Active]);
        holdFireArmed($conversation);
    });

    holdOpen($conversation);
    holdIncoming($conversation, 'cadê meu pedido?');
    holdRunTurns();

    expect(Message::where('conversation_id', $conversation->id)
        ->whereIn('body', HOLD_LINES)
        ->count())->toBe(0);
});

it('sends one line per wait, however many times the job is re-entered', function () {
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation] = holdFixture([
        'holding_message' => ['messages' => HOLD_LINES],
    ]);

    holdFakeChannels(whileThinking: function () use ($conversation) {
        holdFireArmed($conversation);
        holdFireArmed($conversation);
    });

    holdOpen($conversation);
    holdIncoming($conversation, 'cadê meu pedido?');
    holdRunTurns();

    // The claim is spent on the first call: a second bubble saying the same
    // thing reads as a bot stuttering.
    expect(Message::where('conversation_id', $conversation->id)
        ->whereIn('body', HOLD_LINES)
        ->count())->toBe(1);
});

it('reaches for the media lines when the customer sent something instead of typing', function () {
    $config = AiHoldingMessage::config([
        'holding_message' => ['messages' => HOLD_LINES, 'media_messages' => HOLD_MEDIA_LINES],
    ]);

    expect(AiHoldingMessage::pick($config, true, 1))->toBe(HOLD_MEDIA_LINES[0])
        ->and(AiHoldingMessage::pick($config, false, 1))->toBeIn(HOLD_LINES);

    // No media list is not an error — those waits are simply covered by the
    // main one, which is the whole reason it is optional.
    $noMedia = AiHoldingMessage::config(['holding_message' => ['messages' => HOLD_LINES]]);

    expect(AiHoldingMessage::pick($noMedia, true, 1))->toBeIn(HOLD_LINES);
});

it('rotates the lines so waiting twice does not read the same sentence twice', function () {
    $config = AiHoldingMessage::config(['holding_message' => ['messages' => HOLD_LINES]]);

    expect(AiHoldingMessage::pick($config, false, 10))
        ->not->toBe(AiHoldingMessage::pick($config, false, 11));
});

it('treats an empty list, a disabled card and the platform switch as the same silence', function () {
    expect(AiHoldingMessage::config([])['enabled'])->toBeFalse()
        ->and(AiHoldingMessage::config(['holding_message' => ['messages' => ['   ']]])['enabled'])->toBeFalse()
        ->and(AiHoldingMessage::config([
            'holding_message' => ['messages' => HOLD_LINES, 'enabled' => false],
        ])['enabled'])->toBeFalse();

    config()->set('ai.holding.enabled', false);

    expect(AiHoldingMessage::config(['holding_message' => ['messages' => HOLD_LINES]])['enabled'])->toBeFalse();
});

it('clamps the threshold a form could put in', function () {
    $config = AiHoldingMessage::config([
        'holding_message' => ['messages' => HOLD_LINES, 'after_seconds' => 99999],
    ]);

    expect($config['after_seconds'])->toBe(AiHoldingMessage::MAX_AFTER_SECONDS);
});

it('shows the customer that something is being written, from the moment they wrote', function () {
    holdFakeChannels();
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation] = holdFixture();
    holdOpen($conversation);
    holdIncoming($conversation, 'cadê meu pedido?');

    // Armed at the top of the debounce window, not at the hub call: that window
    // is the first half of the wait, and on a node with a long one, most of it.
    Queue::assertPushed(RefreshAiTypingIndicator::class);
    expect(Cache::get(AiTypingPresence::key($conversation->id)))->not->toBeNull();

    holdRunTurns();

    // And withdrawn when the turn ends — API Way's indicator has no timeout of
    // its own, so this call is the only thing that ever clears it there.
    expect(Cache::get(AiTypingPresence::key($conversation->id)))->toBeNull();
});

it('does not try to type on a channel that has no such idea', function () {
    Http::fake();
    Queue::fake([RunAiAgentTurn::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation] = holdFixture([], Channel::Email);

    AiTypingPresence::start($conversation);

    Queue::assertNotPushed(RefreshAiTypingIndicator::class);
});
