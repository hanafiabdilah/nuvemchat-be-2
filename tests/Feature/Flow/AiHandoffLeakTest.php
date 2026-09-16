<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunAiAgentTurn;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/**
 * Two things travel to the hub on every run: the text, and the key the hub
 * files the dialogue under. Both used to carry something that was never the
 * customer's — our own welcome sentence in the first, the contact's phone
 * number in the second — and the hub's handoff detector read both as the
 * customer asking for a human.
 */

/** The welcome as a real one reads: helpful, and full of the detector's keywords. */
const LEAK_WELCOME = 'Olá! Eu sou o Darwin. Se quiser falar com um atendente humano, é só pedir.';

function leakWelcomeNode(FlowNode $node): void
{
    $node->update(['data' => array_merge($node->data, ['welcoming_message' => LEAK_WELCOME])]);
}

/** A customer message, then the webhook's call into the flow. */
function leakSays(Conversation $conversation, string $body): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->startFlow($conversation->fresh());
    (new FlowExecutor)->resumeFlow($conversation->fresh(), $body);
}

function leakRunTurns(): void
{
    foreach (Queue::pushed(RunAiAgentTurn::class)->all() as $turn) {
        $turn->handle();
    }
}

// ---------------------------------------------------------------------------
// Patch 1 — our words are never sent as the customer's
// ---------------------------------------------------------------------------

test('a welcome held for the first answer is named to the hub, never quoted', function () {
    AiAgentFixtures::fakeChannelsAndHub('Claro, posso ajudar.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    leakWelcomeNode($node);

    // Opens with a real question, so the welcome is held back and sent in the
    // bubble immediately above the AI's answer.
    leakSays($conversation, 'preciso de ajuda com minha conta');
    leakRunTurns();

    $content = AiAgentFixtures::hubRuns()[0]['message']['content'];

    expect($content)
        ->toContain('Do not greet the customer or introduce yourself again')
        ->toContain('preciso de ajuda com minha conta')
        // The whole point: the hub scans this field for handoff keywords, and
        // it is the customer's message as far as the hub is concerned.
        ->not->toContain('atendente humano')
        ->not->toContain(LEAK_WELCOME);
});

test('a welcome already sent is named in the transcript, never quoted', function () {
    AiAgentFixtures::fakeChannelsAndHub('Claro, posso ajudar.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation, $node] = AiAgentFixtures::flow();
    leakWelcomeNode($node);

    // A bare greeting: the welcome goes out on its own, and the AI is not run.
    AiAgentFixtures::openWithWelcome($conversation);
    expect(Queue::pushed(RunAiAgentTurn::class))->toBeEmpty();

    // A human said something the agent has to know about. That is what makes
    // the transcript worth sending at all — and it carries the welcome with it.
    $agent = User::factory()->create(['name' => 'Taisa']);
    $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'Estou verificando seu cadastro.',
        'sent_by_user_id' => $agent->id,
        'sent_at' => now(),
    ]);

    // Now a question. The welcome is behind us, so it reaches the agent
    // through the transcript instead of the note.
    leakSays($conversation, 'preciso de ajuda com minha conta');
    leakRunTurns();

    $content = AiAgentFixtures::hubRuns()[0]['message']['content'];

    expect($content)
        ->toContain('You (welcome message)')
        ->not->toContain('atendente humano')
        ->not->toContain(LEAK_WELCOME);
});

// ---------------------------------------------------------------------------
// Patch 2 — one hub conversation per thread
// ---------------------------------------------------------------------------

test('the hub conversation is keyed per conversation, not per channel address', function () {
    AiAgentFixtures::fakeChannelsAndHub('Claro, posso ajudar.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();

    leakSays($conversation, 'preciso de ajuda com minha conta');
    leakRunTurns();

    $sent = AiAgentFixtures::hubRuns()[0]['conversation'];

    expect($sent['externalId'])
        ->toBe("conv:{$conversation->id}")
        // The phone number is still who we are talking to — it is just not the
        // name of this dialogue.
        ->not->toBe($conversation->external_id)
        ->and($sent['contactExternalId'])->toBe('5511999999999');
});

test('the next conversation from the same number starts a fresh hub conversation', function () {
    AiAgentFixtures::fakeChannelsAndHub('Claro, posso ajudar.');
    Queue::fake([RunAiAgentTurn::class]);

    [$first] = AiAgentFixtures::flow();

    leakSays($first, 'primeira dúvida');
    leakRunTurns();

    // The thread is closed and the same number writes again a month later:
    // a new conversations row, same contact, same channel address.
    $first->update(['status' => ConversationStatus::Resolved]);

    $second = Conversation::create([
        'contact_id' => $first->contact_id,
        'connection_id' => $first->connection_id,
        'external_id' => $first->external_id,
        'status' => ConversationStatus::Pending,
    ]);

    leakSays($second, 'segunda dúvida');
    leakRunTurns();

    $keys = array_column(array_column(AiAgentFixtures::hubRuns(), 'conversation'), 'externalId');

    // Nothing said in the first thread — a request for a person included —
    // can be read back out of the hub's memory of the second.
    expect($keys)->toBe(["conv:{$first->id}", "conv:{$second->id}"]);
});
