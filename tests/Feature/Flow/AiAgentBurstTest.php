<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunAiAgentTurn;
use App\Models\AiHubAgent;
use App\Models\AiHubApiKey;
use App\Models\AiHubTenant;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A WhatsApp connection parked on an AIAgent node, with a hub agent behind it.
 *
 * @return array{0: Conversation, 1: FlowNode}
 */
function burstFlowFixture(array $nodeData = []): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $hubTenant = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => 'hub-tenant-b1',
        'external_id' => 'Pingly_b1',
        'name' => 'Pingly_b1',
        'status' => 'ACTIVE',
    ]);

    AiHubApiKey::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_api_key_id' => 'hub-key-b1',
        'name' => 'default',
        'api_key' => 'tenant-api-key',
        'status' => 'ACTIVE',
    ]);

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'hub-agent-b1',
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
        'channel' => Channel::WhatsappOfficial,
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
        'channel' => Channel::WhatsappOfficial,
        'external_id' => '5511988888888',
        'name' => 'Bruno',
        'username' => '5511988888888',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511988888888',
        'status' => ConversationStatus::Pending,
    ]);

    return [$conversation, $ai];
}

function fakeBurstChannelsAndHub(string $aiReply = 'Claro, vou verificar o pedido 123.'): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT' . uniqid()]]]),
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_b1',
            'status' => 'COMPLETED',
            'output' => ['message' => $aiReply, 'handoff' => false],
        ]),
    ]);
}

/** Get past the welcome turn — the first interaction never reaches the AI. */
function openBurstWithWelcome(Conversation $conversation): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.B0',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->startFlow($conversation);
}

/** One inbound text, then the webhook's call into the flow. */
function burstIncoming(Conversation $conversation, string $body): Message
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

/** The run payloads the hub received, in order. */
function burstHubRuns(): array
{
    $runs = [];

    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), 'api-ia.ipbr.pro')) {
            $runs[] = $request->data();
        }
    }

    return $runs;
}

/** Every turn armed so far, oldest first. */
function armedTurns(): array
{
    return Queue::pushed(RunAiAgentTurn::class)->all();
}

/** The delay the node would be armed with, in seconds. */
function turnDelayFor(FlowNode $node): int
{
    return (function () use ($node) {
        return $this->aiTurnDelaySeconds($node);
    })->call(new FlowExecutor);
}

test('a burst of messages is answered once, as a single turn', function () {
    fakeBurstChannelsAndHub();

    // Queued, so nothing runs on arrival — the shape a real worker sees, where
    // the delay is what separates the messages from their answer.
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = burstFlowFixture();
    openBurstWithWelcome($conversation);

    burstIncoming($conversation, 'oi');
    burstIncoming($conversation, 'tenho uma dúvida');
    burstIncoming($conversation, 'sobre o pedido 123');

    // One per message: the customer kept typing, so the turn kept being pushed
    // back. Only the last is still the turn the flow state is waiting for.
    $armed = armedTurns();
    expect($armed)->toHaveCount(3);

    foreach ($armed as $job) {
        $job->handle();
    }

    $runs = burstHubRuns();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe("oi\ntenho uma dúvida\nsobre o pedido 123");

    // And one reply, not three.
    expect(Message::where('sender_type', SenderType::Outgoing)
        ->where('body', 'Claro, vou verificar o pedido 123.')
        ->count())->toBe(1);

    // Nothing left armed, and every message accounted for.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data)->not->toHaveKey("_ai_debounce_token_{$node->id}")
        ->and($state->state_data["_ai_last_processed_message_id_{$node->id}"])
        ->toBe(Message::where('sender_type', SenderType::Incoming)->max('id'));
});

test('a turn armed by an earlier message stands aside for the one after it', function () {
    fakeBurstChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = burstFlowFixture();
    openBurstWithWelcome($conversation);

    burstIncoming($conversation, 'oi');
    burstIncoming($conversation, 'na verdade, é sobre a nota fiscal');

    $armed = armedTurns();

    // The first job wakes up to a token that is no longer its own.
    $armed[0]->handle();
    expect(burstHubRuns())->toBeEmpty();

    // And the flow is still waiting for the turn that replaced it.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_debounce_token_{$node->id}"])->toBe($armed[1]->token);
});

test('a message that arrives after the turn has run gets its own answer', function () {
    fakeBurstChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = burstFlowFixture();
    openBurstWithWelcome($conversation);

    burstIncoming($conversation, 'qual o prazo de entrega?');
    armedTurns()[0]->handle();

    expect(burstHubRuns())->toHaveCount(1);

    // Batching must not swallow a question the customer asks afterwards.
    burstIncoming($conversation, 'e o frete?');
    $armed = armedTurns();
    expect($armed)->toHaveCount(2);
    $armed[1]->handle();

    $runs = burstHubRuns();
    expect($runs)->toHaveCount(2)
        ->and($runs[1]['message']['content'])->toBe('e o frete?');
});

test('the wait is the node setting, then the configured default, and always bounded', function () {
    config([
        'ai.turn_delay_seconds' => 8,
        'ai.max_turn_delay_seconds' => 300,
    ]);

    [, $node] = burstFlowFixture();

    // Flows built before the field existed fall back to the platform default.
    expect(turnDelayFor($node))->toBe(8);

    $set = function ($value) use ($node) {
        $node->forceFill([
            'data' => array_merge($node->data, ['response_delay_seconds' => $value]),
        ])->save();

        return $node;
    };

    expect(turnDelayFor($set(25)))->toBe(25)
        // 0 is a real choice: answer on arrival, the behaviour before the wait
        // existed.
        ->and(turnDelayFor($set(0)))->toBe(0)
        // A value out of a form cannot park a live conversation for an hour.
        ->and(turnDelayFor($set(99999)))->toBe(300)
        ->and(turnDelayFor($set(-5)))->toBe(0);
});
