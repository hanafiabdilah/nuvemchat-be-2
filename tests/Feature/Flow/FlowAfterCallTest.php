<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\CallLog;
use App\Services\Flow\FlowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
});

/** A WhatsApp connection whose flow greets: start → message. */
function afterCallConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Greeter']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $hello = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Olá! Como posso ajudar?', 'message_type' => 'text'],
        'position_x' => 200,
        'position_y' => 0,
    ]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $hello->id, 'condition_value' => null]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => ['phone_number_id' => '106540352242922', 'access_token' => 'test-token', 'business_account_id' => '111'],
    ]);
}

function afterCallCustomerWrites(Conversation $conversation, string $body = 'oi'): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.IN'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);

    FlowRunner::resume($conversation->fresh(), $body);
}

test('a thread opened by a call still starts the flow on the customer\'s first message', function () {
    $connection = afterCallConnection();

    CallLog::record($connection, '5511888887777', 'Maria', 'CALL-1', CallLog::MISSED, now());

    $conversation = Conversation::firstOrFail();
    expect(FlowState::count())->toBe(0);

    afterCallCustomerWrites($conversation);

    expect(FlowState::where('conversation_id', $conversation->id)->exists())->toBeTrue()
        ->and(Message::where('conversation_id', $conversation->id)->where('body', 'Olá! Como posso ajudar?')->exists())->toBeTrue();
});

test('the second message in a call-opened thread resumes, it does not start the flow again', function () {
    $connection = afterCallConnection();
    CallLog::record($connection, '5511888887777', 'Maria', 'CALL-1', CallLog::MISSED, now());
    $conversation = Conversation::firstOrFail();

    afterCallCustomerWrites($conversation);
    afterCallCustomerWrites($conversation->fresh(), 'tudo bem?');

    expect(FlowState::count())->toBe(1)
        ->and(Message::where('body', 'Olá! Como posso ajudar?')->count())->toBe(1);
});

test('a call-opened thread someone already took is left alone', function () {
    $connection = afterCallConnection();
    CallLog::record($connection, '5511888887777', 'Maria', 'CALL-1', CallLog::MISSED, now());
    $conversation = Conversation::firstOrFail();
    $conversation->forceFill([
        'user_id' => $connection->tenant->user_id,
        'status' => ConversationStatus::Active,
    ])->save();

    afterCallCustomerWrites($conversation->fresh());

    expect(FlowState::count())->toBe(0);
});
