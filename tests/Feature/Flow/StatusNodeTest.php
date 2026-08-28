<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The trailing message node must not reach the real Cloud API on the one test
// where it is supposed to run — and must be caught, not silently allowed, on
// the tests where it is not.
beforeEach(fn () => Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]));

/**
 * start → status → message.
 *
 * The trailing message node is the point of the fixture: the builder draws a
 * status node without an output handle, but nothing stops an imported flow from
 * carrying that edge, and closing a conversation has to be the end of the road
 * either way.
 */
function statusNodeFixture(string $value = 'resolved'): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Closer']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $status = $flow->nodes()->create([
        'type' => NodeType::Status,
        'data' => ['value' => $value],
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $after = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Should never be sent.', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $status->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $status->id, 'target_node_id' => $after->id, 'condition_value' => null]);

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

    return [$conversation, compact('start', 'status', 'after')];
}

test('a status node resolves the conversation and stamps who closed it', function () {
    [$conversation] = statusNodeFixture();

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Resolved)
        ->and($conversation->resolved_at)->not->toBeNull()
        // Nobody clicked anything, and inventing an actor would make the
        // resolution stats read as human work.
        ->and($conversation->resolved_by_user_id)->toBeNull();
});

test('a status node ends the flow rather than running the node wired after it', function () {
    [$conversation, $nodes] = statusNodeFixture();

    (new FlowExecutor)->startFlow($conversation);

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Text)
        ->pluck('body')
        ->all();

    expect($sent)->not->toContain('Should never be sent.');

    // Completed, not Stopped: the flow reached the end its author drew, which
    // is a different event from a human interrupting the bot.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->status)->toBe(FlowStateStatus::Completed)
        ->and($state->completed_at)->not->toBeNull()
        ->and($state->current_node_id)->toBe($nodes['status']->id);
});

test('closing writes the thread its own status note', function () {
    [$conversation] = statusNodeFixture();

    (new FlowExecutor)->startFlow($conversation);

    $note = Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->latest('id')
        ->first();

    expect($note)->not->toBeNull()
        // No actor, so the code is the one without a name in it.
        ->and($note->meta['info']['code'])->toBe('conversation_status_changed')
        ->and($note->meta['info']['params']['from_status'])->toBe('pending')
        ->and($note->meta['info']['params']['to_status'])->toBe('resolved')
        // Outgoing keeps it out of the unread badge, which only counts Incoming.
        ->and($note->sender_type)->toBe(SenderType::Outgoing);
});

test('a status node holding anything but resolved is skipped, not guessed at', function () {
    // 'open' was the placeholder the disabled builder shipped, and it is not a
    // status this product has. Acting on it would mean picking one for the
    // author; the flow moves past it instead.
    [$conversation] = statusNodeFixture('open');

    (new FlowExecutor)->startFlow($conversation);

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Pending);

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Text)
        ->pluck('body')
        ->all();

    expect($sent)->toContain('Should never be sent.');
});
