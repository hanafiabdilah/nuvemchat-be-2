<?php

use App\Enums\Broadcast\ContentType;
use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status as BroadcastStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\MessageUpdated;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\MessageService;
use App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Named for this file: Pest loads every test into one process.
function deliveryTestConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);
}

function deliveryTestConversation(Connection $connection): Conversation
{
    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'username' => '5511999999999',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Active,
    ]);
}

function deliveryTestStatus(string $externalId, string $status, array $errors = []): array
{
    return [
        'changes' => [[
            'value' => [
                'statuses' => [array_filter([
                    'id' => $externalId,
                    'status' => $status,
                    'timestamp' => (string) now()->timestamp,
                    'errors' => $errors ?: null,
                ], fn ($v) => $v !== null)],
            ],
        ]],
    ];
}

test('a send is recorded as handed over, not as delivered', function () {
    // Meta answering 200 means it accepted the message, nothing more. Claiming
    // delivery here is what made a refusal invisible for the message's life.
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.A']]])]);

    $connection = deliveryTestConnection();

    $message = (new MessageService())->sendMessage(deliveryTestConversation($connection), [
        'message' => 'Olá',
    ]);

    expect($message->sent_at)->not->toBeNull()
        ->and($message->delivery_at)->toBeNull()
        ->and($message->read_at)->toBeNull()
        ->and($message->error)->toBeNull();
});

test('the status webhook is what marks a message delivered', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.B']]])]);

    $connection = deliveryTestConnection();
    $message = (new MessageService())->sendMessage(deliveryTestConversation($connection), ['message' => 'Olá']);

    (new WhatsappOfficialHandler)->handle($connection, deliveryTestStatus('wamid.B', 'delivered'));

    expect($message->fresh()->delivery_at)->not->toBeNull();
});

test('a refused delivery is written onto the message, not only into the log', function () {
    Event::fake([MessageUpdated::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.C']]])]);

    $connection = deliveryTestConnection();
    $conversation = deliveryTestConversation($connection);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid.C',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Template,
        'body' => 'Olá! Sou do suporte ProxyBR.',
        'sent_at' => now(),
        // Whatever an older row was given at send time.
        'delivery_at' => now(),
    ]);

    // The real payload from production: Meta took the send, then could not
    // fetch the media header the template was sent with.
    (new WhatsappOfficialHandler)->handle($connection, deliveryTestStatus('wamid.C', 'failed', [[
        'code' => 131053,
        'title' => 'Media upload error',
        'message' => 'Media upload error',
        'error_data' => ['details' => 'Downloading media from weblink failed with http code 403, status message Forbidden'],
    ]]));

    $message->refresh();

    expect($message->error)->not->toBeNull()
        // The ticks it was given are now known to be untrue.
        ->and($message->delivery_at)->toBeNull()
        ->and($message->read_at)->toBeNull();

    // Meta's own wording names a shape the agent never saw; ours replaces it.
    expect($message->error)->not->toContain('weblink')
        ->and($message->error)->not->toContain('403');

    Event::assertDispatched(MessageUpdated::class);
});

test('a delivery that succeeds leaves no failure behind', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.D']]])]);

    $connection = deliveryTestConnection();
    $message = (new MessageService())->sendMessage(deliveryTestConversation($connection), ['message' => 'Olá']);

    (new WhatsappOfficialHandler)->handle($connection, deliveryTestStatus('wamid.D', 'read'));

    $message->refresh();

    expect($message->read_at)->not->toBeNull()
        ->and($message->error)->toBeNull();
});

test('a campaign stops counting a refused message as sent', function () {
    $connection = deliveryTestConnection();
    $conversation = deliveryTestConversation($connection);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid.E',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Template,
        'body' => 'Sua oferta chegou.',
        'sent_at' => now(),
        'delivery_at' => now(),
    ]);

    $broadcast = Broadcast::create([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'name' => 'Oferta',
        'content_type' => ContentType::Template,
        'payload' => ['template_name' => 'chamado', 'language' => 'pt_BR'],
        'status' => BroadcastStatus::Completed,
        'rate_per_minute' => 60,
        'total_count' => 2,
        'sent_count' => 2,
        'failed_count' => 0,
    ]);

    $recipient = BroadcastRecipient::create([
        'broadcast_id' => $broadcast->id,
        'conversation_id' => $conversation->id,
        'message_id' => $message->id,
        'address' => '5511999999999',
        'status' => RecipientStatus::Sent,
        'sent_at' => now(),
    ]);

    (new WhatsappOfficialHandler)->handle($connection, deliveryTestStatus('wamid.E', 'failed', [[
        'code' => 131053,
        'title' => 'Media upload error',
        'error_data' => ['details' => 'Downloading media from weblink failed with http code 403, status message Forbidden'],
    ]]));

    $recipient->refresh();
    $broadcast->refresh();

    expect($recipient->status)->toBe(RecipientStatus::Failed)
        ->and($recipient->error)->not->toBeNull()
        // We did hand it over; when is a fact the report keeps.
        ->and($recipient->sent_at)->not->toBeNull()
        ->and($broadcast->sent_count)->toBe(1)
        ->and($broadcast->failed_count)->toBe(1);
});

test('the same refusal arriving twice moves the tally once', function () {
    $connection = deliveryTestConnection();
    $conversation = deliveryTestConversation($connection);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid.F',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Template,
        'body' => 'Sua oferta chegou.',
        'sent_at' => now(),
    ]);

    $broadcast = Broadcast::create([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'name' => 'Oferta',
        'content_type' => ContentType::Template,
        'payload' => ['template_name' => 'chamado', 'language' => 'pt_BR'],
        'status' => BroadcastStatus::Completed,
        'rate_per_minute' => 60,
        'total_count' => 1,
        'sent_count' => 1,
        'failed_count' => 0,
    ]);

    BroadcastRecipient::create([
        'broadcast_id' => $broadcast->id,
        'conversation_id' => $conversation->id,
        'message_id' => $message->id,
        'address' => '5511999999999',
        'status' => RecipientStatus::Sent,
        'sent_at' => now(),
    ]);

    $payload = deliveryTestStatus('wamid.F', 'failed', [['code' => 131053, 'title' => 'Media upload error']]);

    (new WhatsappOfficialHandler)->handle($connection, $payload);
    (new WhatsappOfficialHandler)->handle($connection, $payload);

    $broadcast->refresh();

    expect($broadcast->sent_count)->toBe(0)
        ->and($broadcast->failed_count)->toBe(1);
});
