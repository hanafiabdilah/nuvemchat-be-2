<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RefreshAiTypingIndicator;
use App\Jobs\RunAiAgentTurn;
use App\Jobs\SendAiFollowUp;
use App\Jobs\SendAiHoldingMessage;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\Message;
use App\Services\AiAgentHub\AiFollowUp;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

/**
 * A conversation on an "Agente IA com ações" node that has already answered
 * one question, with $followUp as the node's setting.
 *
 * @return array{0: Conversation, 1: FlowNode}
 */
function followUpFixture(?array $followUp): array
{
    // Its own fake: every run needs a fresh id (ai_hub_runs.hub_run_id is unique).
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]]),
        'api-ia.ipbr.pro/*' => fn () => Http::response([
            'id' => 'run_'.uniqid(),
            'status' => 'COMPLETED',
            'output' => ['message' => 'O pedido 123 sai amanhã. Posso ajudar em algo mais?', 'handoff' => false],
        ]),
    ]);
    Queue::fake([RunAiAgentTurn::class, SendAiFollowUp::class, SendAiHoldingMessage::class, RefreshAiTypingIndicator::class]);

    [$conversation, $node] = AiAgentFixtures::flow();

    $node->update([
        'type' => NodeType::AiTools,
        'data' => array_merge($node->data, $followUp === null ? [] : ['follow_up' => $followUp]),
    ]);

    AiAgentFixtures::openWithWelcome($conversation);

    $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'quando chega meu pedido?',
        'sent_at' => now(),
    ]);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'quando chega meu pedido?');

    foreach (Queue::pushed(RunAiAgentTurn::class)->all() as $job) {
        $job->handle();
    }

    return [$conversation->fresh(), $node->fresh()];
}

/** Fire the newest armed follow-up step, as a worker would. */
function followUpFire(): void
{
    $job = Queue::pushed(SendAiFollowUp::class)->last();

    (new FlowExecutor)->runAiFollowUp($job->flowStateId, $job->nodeId, $job->token);
}

function followUpSent(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->whereNotNull('meta->'.AiFollowUp::META_FLAG)
        ->pluck('body')
        ->all();
}

const FOLLOW_UP_TWO_STEPS = [
    'enabled' => true,
    'steps' => [
        ['delay_minutes' => 30, 'instruction' => ''],
        ['delay_minutes' => 240, 'instruction' => 'Ofereça frete grátis.'],
    ],
];

it('never follows up on a node that was not asked to', function () {
    followUpFixture(null);

    // Absent has to mean off: a flow must not start writing to silent
    // customers because the engine was upgraded.
    Queue::assertNotPushed(SendAiFollowUp::class);
});

it('arms the first step after the agent answers, counted from the answer', function () {
    followUpFixture(FOLLOW_UP_TWO_STEPS);

    $job = Queue::pushed(SendAiFollowUp::class)->last();

    expect($job)->not->toBeNull()
        ->and((int) round(now()->diffInMinutes($job->delay, absolute: true)))->toBe(30);
});

it('lets the agent write the follow-up and arms the next step', function () {
    [$conversation] = followUpFixture(FOLLOW_UP_TWO_STEPS);
    $before = count(AiAgentFixtures::hubRuns());

    followUpFire();

    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount($before + 1)
        ->and($runs[$before]['message']['content'])->toContain('[Follow-up 1 of 2')
        ->and(followUpSent($conversation))->toHaveCount(1);

    $next = Queue::pushed(SendAiFollowUp::class)->last();
    expect((int) round(now()->diffInMinutes($next->delay, absolute: true)))->toBe(240);

    // The second step carries the author's instruction and is the last one.
    followUpFire();

    $runs = AiAgentFixtures::hubRuns();
    expect(end($runs)['message']['content'])
        ->toContain('[Follow-up 2 of 2')
        ->toContain('Ofereça frete grátis.')
        ->and(followUpSent($conversation))->toHaveCount(2);

    $state = $conversation->flowState()->first();
    expect(collect($state->state_data)->keys()->filter(fn ($key) => str_starts_with($key, '_ai_follow_up_')))->toBeEmpty();
});

it('stays silent once the customer has replied', function () {
    [$conversation] = followUpFixture(FOLLOW_UP_TWO_STEPS);
    $armed = Queue::pushed(SendAiFollowUp::class)->last();
    $before = count(AiAgentFixtures::hubRuns());

    // The reply lands and the webhook hands it to the flow, which clears the
    // claim; the job that was already queued must step aside.
    $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'ok, obrigado',
        'sent_at' => now(),
    ]);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'ok, obrigado');

    (new FlowExecutor)->runAiFollowUp($armed->flowStateId, $armed->nodeId, $armed->token);

    expect(AiAgentFixtures::hubRuns())->toHaveCount($before)
        ->and(followUpSent($conversation))->toBeEmpty();
});

it('does not write after a person took the conversation', function () {
    [$conversation] = followUpFixture(FOLLOW_UP_TWO_STEPS);

    $conversation->update(['status' => ConversationStatus::Active]);
    followUpFire();

    expect(followUpSent($conversation))->toBeEmpty();
});

it('does not write once the WhatsApp window has closed', function () {
    [$conversation] = followUpFixture(FOLLOW_UP_TWO_STEPS);

    Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Incoming)
        ->update(['created_at' => now()->subHours(25), 'sent_at' => now()->subHours(25)]);

    followUpFire();

    expect(followUpSent($conversation))->toBeEmpty();
});
