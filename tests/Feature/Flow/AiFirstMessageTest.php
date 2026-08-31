<?php

use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunAiAgentTurn;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Models\Message;
use App\Services\AiAgentHub\AiFirstMessage;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/** The message that opens the conversation, then the webhook's call into the flow. */
function firstMessage(
    Conversation $conversation,
    ?string $body,
    MessageType $type = MessageType::Text,
    ?string $attachment = null,
    ?AttachmentStatus $status = null,
): Message {
    $message = $conversation->messages()->create([
        'external_id' => 'wamid.' . uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => $type,
        'body' => $body,
        'attachment' => $attachment,
        'attachment_status' => $status,
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->startFlow($conversation->fresh());

    return $message;
}

/** Every AI turn armed so far. */
function firstTurnArmed(): array
{
    return Queue::pushed(RunAiAgentTurn::class)->all();
}

function welcomesSent(Conversation $conversation): int
{
    return $conversation->messages()
        ->where('sender_type', SenderType::Outgoing)
        ->where('body', 'Oi! Como posso ajudar?')
        ->count();
}

// ---------------------------------------------------------------------------
// The reading itself
// ---------------------------------------------------------------------------

test('a message that is only a greeting is read as one', function (string $text) {
    expect(AiFirstMessage::isGreetingOnly($text))->toBeTrue();
})->with([
    'oi',
    'Oi',
    'Oiii',
    'olá',
    'Olá!',
    'opa',
    'e aí',
    'bom dia',
    'Bom dia!',
    'boa tarde, tudo bem?',
    'oi, tudo bem?',
    'Oi! Bom dia, tudo bem com você?',
    'salve',
    'blz',
    'hi',
    'Hello!',
    'hey there',   // "there" only follows "hi" in the list, but "hey" + nothing else
    'good morning',
    'hola',
    'buenas tardes',
    'halo',
    'Hai',
    'selamat pagi',
    'permisi',
    'obrigado',
    'ok',
    '👋',
    '🙂🙂',
    '?',
    '',
    '   ',
]);

test('a message with something in it is not read as a greeting', function (string $text) {
    expect(AiFirstMessage::isGreetingOnly($text))->toBeFalse();
})->with([
    'meu pedido não chegou',
    'Bom dia, meu pedido não chegou',
    'oi, preciso de ajuda',
    'quanto custa o plano?',
    'Olá! Gostaria de saber sobre o produto X',
    'me fala o preço',                 // "fala" is a greeting on its own, not here
    'quero falar com um atendente',
    'qual o dia da entrega?',          // "dia" survives only as leftover of "bom dia"
    'você tem esse produto em estoque?',
    'não consigo acessar minha conta',
    'pedido 12345',
    'I need help with my order',
    'oito',                            // must not be eaten by "oi"
]);

// ---------------------------------------------------------------------------
// What the node does with it
// ---------------------------------------------------------------------------

test('a bare greeting gets the welcome and nothing else', function () {
    AiAgentFixtures::fakeChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    $opening = firstMessage($conversation, 'Oi');

    expect(welcomesSent($conversation))->toBe(1)
        ->and(firstTurnArmed())->toBeEmpty();

    // The greeting is answered by the welcome, so it is marked as answered.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBe($opening->id)
        ->and($state->state_data["_ai_turns_{$node->id}"])->toBe(1);
});

test('an opening that carries a question gets the welcome and an answer', function () {
    AiAgentFixtures::fakeChannelsAndHub('Vou verificar o seu pedido.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    $opening = firstMessage($conversation, 'Bom dia, meu pedido não chegou');

    expect(welcomesSent($conversation))->toBe(1);

    $armed = firstTurnArmed();
    expect($armed)->toHaveCount(1);

    // Nothing is marked answered yet — that is what puts the opening in the turn.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBe(0);

    $armed[0]->handle();

    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe('Bom dia, meu pedido não chegou');

    expect($conversation->messages()
        ->where('sender_type', SenderType::Outgoing)
        ->where('body', 'Vou verificar o seu pedido.')
        ->count())->toBe(1);

    $state->refresh();
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBe($opening->id);
});

test('the welcome goes out before the AI answer', function () {
    AiAgentFixtures::fakeChannelsAndHub('Vou verificar o seu pedido.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    firstMessage($conversation, 'meu pedido não chegou');

    firstTurnArmed()[0]->handle();

    // Info notes share the Outgoing side but are never sent anywhere.
    $outgoing = $conversation->messages()
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();

    expect($outgoing)->toBe(['Oi! Como posso ajudar?', 'Vou verificar o seu pedido.']);
});

test('a greeting followed by the question is one opening, answered once', function () {
    AiAgentFixtures::fakeChannelsAndHub('Vou verificar o seu pedido.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();

    // Both land before the flow is entered — the shape a burst arrives in when
    // a Delay node sits in front of the agent.
    $conversation->messages()->create([
        'external_id' => 'wamid.A',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now(),
    ]);

    $question = firstMessage($conversation, 'meu pedido não chegou');

    expect(welcomesSent($conversation))->toBe(1)
        ->and(firstTurnArmed())->toHaveCount(1);

    firstTurnArmed()[0]->handle();

    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe("oi\nmeu pedido não chegou");

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBe($question->id);
});

test('a screenshot opens the conversation with something to answer', function () {
    AiAgentFixtures::fakeChannelsAndHub('Vi o erro no seu print.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    firstMessage($conversation, null, MessageType::Image, 'messages/erro.png');

    expect(welcomesSent($conversation))->toBe(1)
        ->and(firstTurnArmed())->toHaveCount(1);
});

test('a sticker is a wave, not a question', function () {
    AiAgentFixtures::fakeChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    firstMessage($conversation, null, MessageType::Sticker, 'messages/tchau.webp');

    expect(welcomesSent($conversation))->toBe(1)
        ->and(firstTurnArmed())->toBeEmpty();
});

test('the node can be told to keep greeting and waiting', function () {
    AiAgentFixtures::fakeChannelsAndHub();
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    $node->update(['data' => array_merge($node->data, ['answer_first_message' => false])]);

    $opening = firstMessage($conversation, 'meu pedido não chegou');

    expect(welcomesSent($conversation))->toBe(1)
        ->and(firstTurnArmed())->toBeEmpty();

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBe($opening->id);
});

test('the second message is still answered normally after a greeting-only opening', function () {
    AiAgentFixtures::fakeChannelsAndHub('Claro, me conte mais.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    firstMessage($conversation, 'Oi');

    $conversation->messages()->create([
        'external_id' => 'wamid.B',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'meu pedido não chegou',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'meu pedido não chegou');

    $armed = firstTurnArmed();
    expect($armed)->toHaveCount(1);

    $armed[0]->handle();

    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe('meu pedido não chegou');
});
