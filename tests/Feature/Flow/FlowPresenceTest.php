<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RefreshFlowPresence;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\FlowPresence;
use App\Services\Flow\MessageNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
    ]);

    Queue::fake();
});

/** A WhatsApp workspace with one pending conversation the customer has written into. */
function presenceWorkspace(Channel $channel = Channel::WhatsappOfficial): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Presença']);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Conn',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
            'instance_id' => 'INST-1',
            'token' => 'core-token',
        ],
    ]);

    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511977776666',
        'name' => 'Caio',
        'username' => '5511977776666',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511977776666',
        'status' => ConversationStatus::Pending,
    ]);

    // Cloud API pins the indicator to an inbound message id, so there has to
    // be one for the call to be made at all.
    $conversation->messages()->create([
        'external_id' => 'wamid.INBOUND',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now(),
    ]);

    return [$flow, $conversation];
}

/** start → a message node holding `$bubbles`. Returns the node and its flow state. */
function presenceFixture(array $bubbles, Channel $channel = Channel::WhatsappOfficial): array
{
    [$flow, $conversation] = presenceWorkspace($channel);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $node = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['messages' => $bubbles],
        'position_x' => 300,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $node->id]);

    (new FlowExecutor)->startFlow($conversation, $flow);

    return [$node, FlowState::where('conversation_id', $conversation->id)->first(), $conversation->fresh()];
}

it('fills a pause the author wrote, without being asked to', function () {
    [$node, $state] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 8],
    ]);

    $token = $state->refresh()->state_data["_message_chain_{$node->id}"]['token'];

    // Every pause in production is a number somebody typed into a field
    // labelled "wait before sending" — a request for a sequence that reads like
    // writing, which is exactly what the indicator delivers.
    Queue::assertPushed(RefreshFlowPresence::class, fn ($job) => $job->token === $token
        && $job->claimKey === "_message_chain_{$node->id}"
        && $job->kind === 'typing'
        && $job->deadline === now()->addSeconds(8)->timestamp);
});

it('says gravando áudio before a voice note', function () {
    presenceFixture([
        ['message_type' => 'audio', 'body' => '', 'attachment_url' => 'https://cdn.example.com/a.ogg', 'delay' => 6],
    ]);

    Queue::assertPushed(RefreshFlowPresence::class, fn ($job) => $job->kind === 'recording');
});

it('says enviando arquivo before an image', function () {
    presenceFixture([
        ['message_type' => 'image', 'body' => '', 'attachment_url' => 'https://cdn.example.com/a.jpg', 'delay' => 6],
    ]);

    Queue::assertPushed(RefreshFlowPresence::class, fn ($job) => $job->kind === 'uploading');
});

it('leaves a pause silent when its author said so', function () {
    presenceFixture([
        ['message_type' => 'text', 'body' => 'Pensando…', 'delay' => 9, 'presence' => false],
    ]);

    Queue::assertNotPushed(RefreshFlowPresence::class);
});

it('reads a missing presence key as on, so flows written before it gain it', function () {
    // Absent must not read as off: every node saved before the key existed
    // still has a pause its author wanted to read like typing.
    expect(MessageNodes::presenceEnabled(['delay' => 5]))->toBeTrue()
        ->and(MessageNodes::presenceEnabled(['presence' => false]))->toBeFalse()
        ->and(MessageNodes::presenceEnabled(['presence' => true]))->toBeTrue();
});

it('does nothing at all when there is no pause to fill', function () {
    presenceFixture([
        ['message_type' => 'text', 'body' => 'Olá!', 'delay' => 0],
    ]);

    Queue::assertNotPushed(RefreshFlowPresence::class);
});

it('can be taken back across every flow at once', function () {
    config()->set('flow.presence.enabled', false);

    presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 8],
    ]);

    Queue::assertNotPushed(RefreshFlowPresence::class);
});

it('covers the end of a long pause rather than its beginning', function () {
    config()->set('flow.presence.max_seconds', 30);

    presenceFixture([
        ['message_type' => 'audio', 'body' => '', 'attachment_url' => 'https://cdn.example.com/a.ogg', 'delay' => 120],
    ]);

    // Nobody records a voice note for two minutes. Silence, then "gravando
    // áudio…", then the message is the sequence a person produces — so the
    // episode starts 90s in and runs to the end.
    Queue::assertPushed(RefreshFlowPresence::class, fn ($job) => $job->delay->timestamp === now()->addSeconds(90)->timestamp
        && $job->deadline === now()->addSeconds(120)->timestamp);
});

it('fills the pause before each later bubble too', function () {
    [$node, $state, $conversation] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Um', 'delay' => 4],
        ['message_type' => 'audio', 'body' => '', 'attachment_url' => 'https://cdn.example.com/a.ogg', 'delay' => 5],
    ]);

    $token = $state->refresh()->state_data["_message_chain_{$node->id}"]['token'];

    (new FlowExecutor)->runScheduledMessageItem($state->id, $node->id, 0, $token);

    Queue::assertPushed(RefreshFlowPresence::class, fn ($job) => $job->kind === 'recording'
        && $job->deadline === now()->addSeconds(5)->timestamp);
});

it('asserts the indicator and queues the next beat', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    // The suite runs on the sync driver, where a delayed dispatch runs now —
    // and a beat that re-entered itself immediately would never stop. That
    // branch is pinned by its own test below; this one is about the loop.
    config()->set('queue.default', 'database');

    [$node, $state] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 30],
    ]);

    $claimKey = "_message_chain_{$node->id}";
    $token = $state->refresh()->state_data[$claimKey]['token'];

    FlowPresence::beat($state->id, $claimKey, $token, 'typing', now()->addSeconds(30)->timestamp);

    Http::assertSent(fn ($request) => ($request['typing_indicator'] ?? null) === ['type' => 'text']);

    // Every indicator is a dead man's switch, so the episode is a job that
    // re-queues itself until the pause is over.
    Queue::assertPushed(RefreshFlowPresence::class, fn ($job) => $job->beat === 2);
});

it('stops beating once the sequence is no longer the live one', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    [$node, $state] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 30],
    ]);

    FlowPresence::beat($state->id, "_message_chain_{$node->id}", 'a-stale-token', 'typing', now()->addSeconds(30)->timestamp);

    Http::assertNothingSent();
});

it('stops beating once the pause is over', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    [$node, $state] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 30],
    ]);

    $claimKey = "_message_chain_{$node->id}";
    $token = $state->refresh()->state_data[$claimKey]['token'];

    // Past the deadline the bubble is landing in the same instant, and every
    // channel clears the indicator when a message arrives.
    FlowPresence::beat($state->id, $claimKey, $token, 'typing', now()->subSecond()->timestamp);

    Http::assertNothingSent();
});

it('withdraws the indicator when a person takes the conversation mid-pause', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    [$node, $state, $conversation] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 30],
    ], Channel::WhatsappApiway);

    $claimKey = "_message_chain_{$node->id}";
    $token = $state->refresh()->state_data[$claimKey]['token'];

    $conversation->forceFill(['status' => ConversationStatus::Active])->save();

    FlowPresence::beat($state->id, $claimKey, $token, 'typing', now()->addSeconds(30)->timestamp);

    // No message is coming to clear it, and API Way's indicator has no timeout
    // at all — so without this the customer watches a bot type forever.
    Http::assertSent(fn ($request) => ($request['presence'] ?? null) === 'paused');
});

it('never dresses up a retry as typing', function () {
    // A retry backoff is the channel being unreachable. Telling the customer we
    // are typing while we are in fact failing to reach WhatsApp is the one
    // thing worse than the silence.
    Http::fake(['graph.facebook.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
        'cURL error 7: Failed to connect to graph.facebook.com port 443'
    )]);

    presenceFixture([
        ['message_type' => 'text', 'body' => 'Olá!', 'delay' => 0],
    ]);

    Queue::assertNotPushed(RefreshFlowPresence::class);
});

it('beats once and no more where queued work runs inline', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    [$node, $state] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 30],
    ]);

    $claimKey = "_message_chain_{$node->id}";
    $token = $state->refresh()->state_data[$claimKey]['token'];

    // Arming the node queued the episode's first beat; this is about what
    // the beat itself does next, so the recorder starts clean.
    Queue::fake();

    expect(config('queue.default'))->toBe('sync');

    FlowPresence::beat($state->id, $claimKey, $token, 'typing', now()->addSeconds(30)->timestamp);

    // A deployment on `sync` genuinely has no scheduler behind it, so the
    // honest behaviour is one indicator that expires on the channel's own
    // countdown — not a job that calls itself for the rest of the day.
    Queue::assertNotPushed(RefreshFlowPresence::class);
    Http::assertSentCount(1);
});

it('stops scheduling when the next beat would land after the message', function () {
    Http::fake(['*' => Http::response(['success' => true])]);
    config()->set('queue.default', 'database');

    [$node, $state] = presenceFixture([
        ['message_type' => 'text', 'body' => 'Só um instante', 'delay' => 30],
    ]);

    $claimKey = "_message_chain_{$node->id}";
    $token = $state->refresh()->state_data[$claimKey]['token'];

    // Arming the node queued the episode's first beat; this is about what
    // the beat itself does next, so the recorder starts clean.
    Queue::fake();

    // WhatsApp Official refreshes every 10s; with 3s left there is no room for
    // another beat, and the one already lit carries to the end on its own.
    FlowPresence::beat($state->id, $claimKey, $token, 'typing', now()->addSeconds(3)->timestamp);

    Http::assertSentCount(1);
    Queue::assertNotPushed(RefreshFlowPresence::class);
});
