<?php

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunAiAgentTurn;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/** A message already in the thread before the AI node is reached. */
function contextMessage(Conversation $conversation, SenderType $side, string $body, array $extra = []): Message
{
    return $conversation->messages()->create(array_merge([
        'external_id' => 'wamid.' . uniqid(),
        'sender_type' => $side,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ], $extra));
}

/**
 * The thread from the report: the flow asked what was wrong, the customer said
 * the lights were out, and the flow explained it was a power outage — none of
 * which went through the hub.
 *
 * @return array<string, Message>
 */
function powerOutageThread(Conversation $conversation): array
{
    return [
        'greeting' => contextMessage($conversation, SenderType::Incoming, 'Oi'),
        'question' => contextMessage($conversation, SenderType::Outgoing, 'Halo, dengan Hana AI disini. Ada yang bisa di bantu?'),
        'problem' => contextMessage($conversation, SenderType::Incoming, 'lampu mati'),
        'thanks' => contextMessage($conversation, SenderType::Outgoing, 'Terima kasih atas laporannya.'),
        'outage' => contextMessage($conversation, SenderType::Outgoing, 'Mohon maaf, server Cipayung sedang mengalami mati listrik.'),
    ];
}

function armedContextTurns(): array
{
    return Queue::pushed(RunAiAgentTurn::class)->all();
}

function contextState(Conversation $conversation): FlowState
{
    return FlowState::where('conversation_id', $conversation->id)->first();
}

test('the agent is told what the flow said before it was reached', function () {
    AiAgentFixtures::fakeChannelsAndHub('A previsão é de 2 horas.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    $thread = powerOutageThread($conversation);
    $reply = contextMessage($conversation, SenderType::Incoming, 'berapa lama lagi normal?');

    (new FlowExecutor)->startFlow($conversation->fresh());

    // Everything up to the flow's last message was already dealt with.
    expect(contextState($conversation)->state_data["_ai_last_processed_message_id_{$node->id}"])
        ->toBe($thread['outage']->id);

    armedContextTurns()[0]->handle();

    $content = AiAgentFixtures::hubRuns()[0]['message']['content'];

    expect($content)
        ->toContain('Customer: Oi')
        ->toContain('Automated message: Halo, dengan Hana AI disini. Ada yang bisa di bantu?')
        ->toContain('Customer: lampu mati')
        ->toContain('Automated message: Mohon maaf, server Cipayung sedang mengalami mati listrik.')
        // The welcome is held for this answer and sent right above it, so the
        // agent learns it from the note ahead of the input, not the transcript.
        ->toContain('Your welcome message is being sent to the customer in the bubble immediately above this reply')
        // Named, never quoted: this string reaches the hub as the customer's
        // message, and a welcome offering a human agent reads to the hub's
        // handoff detector as the customer asking for one.
        ->not->toContain('Oi! Como posso ajudar?')
        ->not->toContain('You (welcome message)')
        ->toEndWith("The customer's new message — reply to this:\nberapa lama lagi normal?");

    // In the order it was said, and the customer's question only once — as
    // the thing to answer, not inside the transcript.
    expect(strpos($content, 'lampu mati'))->toBeLessThan(strpos($content, 'mati listrik'))
        ->and(substr_count($content, 'berapa lama lagi normal?'))->toBe(1);

    expect(contextState($conversation)->state_data["_ai_last_processed_message_id_{$node->id}"])
        ->toBe($reply->id);
});

test('reached right after the flow spoke, the node greets and waits for the customer', function () {
    AiAgentFixtures::fakeChannelsAndHub('A previsão é de 2 horas.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    $thread = powerOutageThread($conversation);

    (new FlowExecutor)->startFlow($conversation->fresh());

    // The flow already answered "lampu mati"; nothing is owed until they write.
    expect(armedContextTurns())->toBeEmpty()
        ->and($conversation->messages()->where('body', 'Oi! Como posso ajudar?')->count())->toBe(1);

    $state = contextState($conversation);
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBe($thread['outage']->id)
        ->and($state->state_data["_ai_turns_{$node->id}"])->toBe(1);

    contextMessage($conversation, SenderType::Incoming, 'ok, berapa lama?');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'ok, berapa lama?');

    armedContextTurns()[0]->handle();

    expect(AiAgentFixtures::hubRuns()[0]['message']['content'])
        ->toContain('Automated message: Mohon maaf, server Cipayung sedang mengalami mati listrik.')
        // Named, not quoted — the transcript reaches the hub as the customer's
        // own message, and a welcome that offers a human agent reads there as
        // the customer asking for one.
        ->toContain('You (welcome message): (your opening message was delivered to the customer)')
        ->not->toContain('Oi! Como posso ajudar?')
        ->toEndWith("reply to this:\nok, berapa lama?");
});

test('the transcript is sent once, not on every turn', function () {
    AiAgentFixtures::fakeChannelsAndHub('A previsão é de 2 horas.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    powerOutageThread($conversation);
    contextMessage($conversation, SenderType::Incoming, 'berapa lama lagi normal?');

    (new FlowExecutor)->startFlow($conversation->fresh());
    armedContextTurns()[0]->handle();

    contextMessage($conversation, SenderType::Incoming, 'e o que eu faço enquanto isso?');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'e o que eu faço enquanto isso?');

    $turns = armedContextTurns();
    end($turns)->handle();

    // The hub already holds the first turn, and its own reply.
    expect(AiAgentFixtures::hubRuns()[1]['message']['content'])->toBe('e o que eu faço enquanto isso?');
});

test('a person who wrote in between reaches the agent under their name', function () {
    AiAgentFixtures::fakeChannelsAndHub('A previsão é de 2 horas.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    $agent = User::factory()->create(['name' => 'Bruno']);

    contextMessage($conversation, SenderType::Incoming, 'lampu mati');
    contextMessage($conversation, SenderType::Outgoing, 'Estamos verificando com a concessionária.', [
        'sent_by_user_id' => $agent->id,
    ]);
    contextMessage($conversation, SenderType::Incoming, 'quando volta?');

    (new FlowExecutor)->startFlow($conversation->fresh());
    armedContextTurns()[0]->handle();

    expect(AiAgentFixtures::hubRuns()[0]['message']['content'])
        ->toContain('Human agent (Bruno): Estamos verificando com a concessionária.')
        ->toEndWith("reply to this:\nquando volta?");
});

test('internal notes never reach the agent', function () {
    AiAgentFixtures::fakeChannelsAndHub('A previsão é de 2 horas.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();

    contextMessage($conversation, SenderType::Incoming, 'lampu mati');
    contextMessage($conversation, SenderType::Outgoing, 'Mohon maaf, sedang mati listrik.');
    contextMessage($conversation, SenderType::Outgoing, 'cliente VIP, cuidado', ['message_type' => MessageType::Info]);
    contextMessage($conversation, SenderType::Incoming, 'quando volta?');

    (new FlowExecutor)->startFlow($conversation->fresh());
    armedContextTurns()[0]->handle();

    expect(AiAgentFixtures::hubRuns()[0]['message']['content'])
        ->toContain('Automated message: Mohon maaf, sedang mati listrik.')
        ->not->toContain('cliente VIP');
});
