<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Jobs\RunFlowTurn;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * Where a flow turn runs.
 *
 * Flows execute inside the request that delivered the customer's message, so a
 * message node's channel call and an http_request node's endpoint sit on the
 * critical path of a webhook — enough inbound traffic against a slow flow and
 * the PHP-FPM pool is full, which is the whole platform answering 502.
 *
 * The switch that moves the turn to a queue is off by default, and these cover
 * both sides of it: that off changes nothing, and that on actually gets off the
 * request.
 */
uses(RefreshDatabase::class);

function flowRunnerConversation(): Conversation
{
    $user = User::factory()->create(['email' => 'runner-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'name' => 'Runner',
        'channel' => Channel::Telegram,
        'status' => ConnectionStatus::Active,
        'credentials' => ['id' => 1, 'token' => 'tok'],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'name' => 'Cliente',
        'external_id' => '55119'.random_int(10000000, 99999999),
        'channel' => Channel::Telegram,
    ]);

    return Conversation::create([
        'connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_id' => $contact->external_id,
    ]);
}

/** A stand-in that records the calls instead of running a flow. */
function flowRunnerSpy(): FlowExecutor
{
    $spy = new class extends FlowExecutor
    {
        public array $calls = [];

        public function startFlow(Conversation $conversation): void
        {
            $this->calls[] = ['start', $conversation->id, null];
        }

        public function resumeFlow(Conversation $conversation, string $userInput): void
        {
            $this->calls[] = ['resume', $conversation->id, $userInput];
        }
    };

    app()->instance(FlowExecutor::class, $spy);

    return $spy;
}

it('runs the turn inline while the switch is off, which is the default', function () {
    Queue::fake();
    config(['flow.queue' => false]);

    $conversation = flowRunnerConversation();
    $spy = flowRunnerSpy();

    FlowRunner::start($conversation);
    FlowRunner::resume($conversation, 'oi');

    expect($spy->calls)->toBe([
        ['start', $conversation->id, null],
        ['resume', $conversation->id, 'oi'],
    ]);

    // Not assertNothingPushed: creating the conversation queues
    // EnsureLeadForConversation, which has nothing to do with flows.
    Queue::assertNotPushed(RunFlowTurn::class);
});

it('gets the turn off the request once the switch is on', function () {
    Queue::fake();
    config(['flow.queue' => true]);

    $conversation = flowRunnerConversation();
    $spy = flowRunnerSpy();

    FlowRunner::start($conversation);
    FlowRunner::resume($conversation, 'quero falar com alguém');

    // The webhook did no flow work at all — that is the entire point.
    expect($spy->calls)->toBe([]);

    Queue::assertPushed(RunFlowTurn::class, 2);
});

it('re-reads the conversation in the job rather than carrying a stale copy', function () {
    config(['flow.queue' => true]);

    $conversation = flowRunnerConversation();
    $spy = flowRunnerSpy();

    (new RunFlowTurn($conversation->id, RunFlowTurn::RESUME, 'bom dia'))->handle($spy);

    expect($spy->calls)->toBe([['resume', $conversation->id, 'bom dia']]);
});

it('does nothing when the conversation is gone by the time the job runs', function () {
    $spy = flowRunnerSpy();

    (new RunFlowTurn(999_999, RunFlowTurn::START))->handle($spy);

    expect($spy->calls)->toBe([]);
});

it('keeps a failing turn from taking the delivery down with it', function () {
    // $tries is 1, so rethrowing would only move the message into failed_jobs.
    // Every call site this replaced swallowed its own errors the same way.
    $conversation = flowRunnerConversation();

    $exploding = new class extends FlowExecutor
    {
        public function startFlow(Conversation $conversation): void
        {
            throw new RuntimeException('node blew up');
        }
    };

    (new RunFlowTurn($conversation->id, RunFlowTurn::START))->handle($exploding);
})->throwsNoExceptions();

it('never attempts a flow turn twice', function () {
    // A retry is not a second attempt at one delivery — it is a second set of
    // bubbles in front of a customer who already received the first set.
    expect((new RunFlowTurn(1, RunFlowTurn::START))->tries)->toBe(1);
});
