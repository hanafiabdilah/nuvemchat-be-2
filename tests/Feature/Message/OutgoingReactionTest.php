<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\Handlers\WhatsappApiwayHandler as ApiwaySendHandler;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler as ApiwayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Reactions find their target through `messages.external_id`. An outgoing row
 * saved without the id the WhatsApp node assigned can never be reacted to —
 * which is exactly how "reactions only stick to incoming messages" looked from
 * the outside. These tests pin both halves: the id is persisted on send, and a
 * reaction whose target is outgoing is stored.
 */
const OUT_REACT_PHONE = '555491094949';

function outgoingReactionConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);
}

function outgoingReactionConversation(Connection $connection): Conversation
{
    $contact = \App\Models\Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => OUT_REACT_PHONE,
        'channel' => $connection->channel,
        'name' => 'Alice',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => OUT_REACT_PHONE,
        'status' => ConversationStatus::Active,
    ]);
}

/** The contact reacting to one of OUR messages: key.fromMe is true. */
function reactionToOurMessage(string $targetId, string $emoji): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => 'REACT-'.$targetId,
                'Chat' => OUT_REACT_PHONE.'@s.whatsapp.net',
                'Sender' => OUT_REACT_PHONE.'@s.whatsapp.net',
                'SenderAlt' => OUT_REACT_PHONE.'@s.whatsapp.net',
                'PushName' => 'Alice',
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => '2026-08-10T10:29:56-03:00',
                'Type' => 'reaction',
            ],
            'Message' => [
                'reactionMessage' => [
                    'key' => [
                        'ID' => $targetId,
                        'fromMe' => true,
                        'remoteJID' => OUT_REACT_PHONE.'@s.whatsapp.net',
                    ],
                    'text' => $emoji,
                ],
            ],
        ],
    ];
}

test('a send response carrying the id as a bare data string still persists external_id', function () {
    Event::fake();
    $connection = outgoingReactionConnection();
    $conversation = outgoingReactionConversation($connection);

    // The published collection types `data` as a plain string, and the core
    // passes the node's payload through verbatim — so the id can arrive as the
    // value of `data` rather than `data.id`.
    Http::fake(['*/v1/message/send-text*' => Http::response(['success' => true, 'data' => 'WAMID-BARE-1'])]);

    $message = (new ApiwaySendHandler)->handleSendMessage($conversation, ['message' => 'Oi']);

    expect($message->external_id)->toBe('WAMID-BARE-1');
});

test('a send response carrying data.id persists external_id', function () {
    Event::fake();
    $connection = outgoingReactionConnection();
    $conversation = outgoingReactionConversation($connection);

    Http::fake(['*/v1/message/send-text*' => Http::response([
        'success' => true,
        'data' => ['id' => 'WAMID-OBJ-1', 'status' => 'PENDING'],
    ])]);

    $message = (new ApiwaySendHandler)->handleSendMessage($conversation, ['message' => 'Oi']);

    expect($message->external_id)->toBe('WAMID-OBJ-1');
});

test('a reaction on an outgoing message is stored against it', function () {
    Event::fake();
    $connection = outgoingReactionConnection();
    $conversation = outgoingReactionConversation($connection);

    Http::fake(['*/v1/message/send-text*' => Http::response(['success' => true, 'data' => 'WAMID-OUT-1'])]);
    $sent = (new ApiwaySendHandler)->handleSendMessage($conversation, ['message' => 'Bom dia']);

    (new ApiwayWebhookHandler)->handle($connection, reactionToOurMessage('WAMID-OUT-1', '❤️'));

    $sent->refresh();

    expect($sent->sender_type)->toBe(SenderType::Outgoing)
        ->and($sent->reactions)->toHaveCount(1)
        ->and($sent->reactions->first()->emoji)->toBe('❤️')
        // The contact reacted, so the reaction itself is incoming even though
        // the message it decorates is ours.
        ->and($sent->reactions->first()->sender_type)->toBe(SenderType::Incoming)
        ->and(Message::count())->toBe(1); // the reaction is not its own bubble
});

test('an outgoing message saved without an id cannot be matched, and the send warns about it', function () {
    Event::fake();
    $connection = outgoingReactionConnection();
    $conversation = outgoingReactionConversation($connection);

    // A response with no id anywhere: the row is still created (the message
    // did go out) but nothing can attach to it later — hence the warning.
    Http::fake(['*/v1/message/send-text*' => Http::response(['success' => true, 'data' => ''])]);

    $message = (new ApiwaySendHandler)->handleSendMessage($conversation, ['message' => 'Oi']);

    expect($message->external_id)->toBe('');
});

test('the resource reports who reacted and when', function () {
    Event::fake();
    $connection = outgoingReactionConnection();
    $conversation = outgoingReactionConversation($connection);

    $message = $conversation->messages()->create([
        'external_id' => 'WAMID-RES-1',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    $message->reactions()->create(['emoji' => '👍', 'sender_type' => SenderType::Incoming]);

    $payload = MessageResource::make($message->fresh()->load('reactions.contact'))->toArray(request());

    expect($payload['reactions'])->toHaveCount(1)
        ->and($payload['reactions'][0]['emoji'])->toBe('👍')
        ->and($payload['reactions'][0]['created_at'])->toBeInt()
        ->and($payload['reactions'][0])->toHaveKey('id')
        // Private chat: sender_type alone identifies the reactor.
        ->and($payload['reactions'][0]['contact'])->toBeNull();
});
