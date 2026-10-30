<?php

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Flow\FlowRunner;
use App\Services\V1\SendMessage\SentMessageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublicApiFixtures as F;

/*
 * A WhatsApp message sent through POST /v1/send-message lands in the inbox.
 *
 * Neither WhatsApp channel echoes what the API sent, so before this the send
 * reached the customer and left nothing in Pingly: no thread, no bubble, and a
 * reply that opened a fresh conversation with no trace of what it answered.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false]);
});

function inboxApiConnection(User $owner, Channel $channel): Connection
{
    $connection = F::connection($owner, $channel, 'Canal '.$channel->value);
    $connection->update(['credentials' => $channel === Channel::WhatsappOfficial
        ? ['access_token' => 'tok', 'phone_number_id' => '111']
        : ['token' => 'tok', 'instance_id' => 'inst-1']]);

    return $connection;
}

function inboxApiSend(User $owner, array $body)
{
    return test()->withHeaders(['X-Api-Key' => F::key($owner)])->postJson('/api/v1/send-message', $body);
}

/** The core's current envelope: the id one level deeper than it looks. */
function apiwaySent(string $id): array
{
    return ['success' => true, 'data' => ['code' => 200, 'success' => true, 'data' => ['Details' => 'Sent', 'Id' => $id, 'Timestamp' => 1792750986]]];
}

it('opens a pending thread for a number the inbox has never seen, with the message in it', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    Http::fake(['*' => Http::response(apiwaySent('3EB0BE97A0350C7A3FF506'))]);
    $owner = F::owner();
    $connection = inboxApiConnection($owner, Channel::WhatsappApiway);

    inboxApiSend($owner, [
        'connection_id' => $connection->public_id,
        'phone' => '6282122787699',
        'message' => 'Seu pedido saiu para entrega',
    ])->assertCreated();

    $contact = Contact::where('external_id', '6282122787699')->sole();
    $conversation = Conversation::where('connection_id', $connection->id)->sole();
    $message = $conversation->messages()->sole();

    expect($contact->tenant_id)->toBe($owner->tenant_id)
        ->and($conversation->contact_id)->toBe($contact->id)
        ->and($conversation->external_id)->toBe('6282122787699')
        // The API is not an agent: the thread waits in the queue every agent watches.
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->user_id)->toBeNull()
        ->and($message->sender_type)->toBe(SenderType::Outgoing)
        ->and($message->message_type)->toBe(MessageType::Text)
        ->and($message->body)->toBe('Seu pedido saiu para entrega')
        // What receipts, reactions and replies locate the message by.
        ->and($message->external_id)->toBe('3EB0BE97A0350C7A3FF506')
        ->and($message->sent_by_user_id)->toBeNull()
        ->and($message->meta[SentMessageRecorder::META_KEY])->toBe(SentMessageRecorder::VIA_API);

    Event::assertDispatched(MessageReceived::class);
    Event::assertDispatched(ConversationUpdated::class);
});

it('adds to a thread already open without moving or reassigning it', function () {
    Http::fake(['*' => Http::response(apiwaySent('OUT-2'))]);
    $owner = F::owner();
    $connection = inboxApiConnection($owner, Channel::WhatsappApiway);
    $agent = F::agent($owner, $connection);

    $contact = Contact::createFromExternalData($connection, '5511987654321', 'Maria');
    $open = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511987654321',
        'status' => ConversationStatus::Active,
        'user_id' => $agent->id,
    ]);

    inboxApiSend($owner, [
        'connection_id' => $connection->public_id,
        'phone' => '5511987654321',
        'message' => 'Lembrete',
    ])->assertCreated();

    expect(Conversation::where('connection_id', $connection->id)->count())->toBe(1)
        ->and($open->fresh()->status)->toBe(ConversationStatus::Active)
        ->and($open->fresh()->user_id)->toBe($agent->id)
        ->and($open->messages()->sole()->body)->toBe('Lembrete')
        ->and(Contact::count())->toBe(1)
        ->and($contact->fresh()->name)->toBe('Maria');
});

it('opens a new thread when the last one was resolved, and finds the contact under either spelling of the number', function () {
    Http::fake(['*' => Http::response(apiwaySent('OUT-3'))]);
    $owner = F::owner();
    $connection = inboxApiConnection($owner, Channel::WhatsappApiway);

    // WhatsApp still reports some Brazilian mobiles without the ninth digit.
    $contact = Contact::createFromExternalData($connection, '551187654321', 'Maria');
    $closed = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '551187654321',
        'status' => ConversationStatus::Resolved,
    ]);

    inboxApiSend($owner, [
        'connection_id' => $connection->public_id,
        'phone' => '5511987654321',
        'message' => 'Oi Maria',
    ])->assertCreated();

    $new = Conversation::where('connection_id', $connection->id)->whereKeyNot($closed->id)->sole();

    expect(Contact::count())->toBe(1)
        ->and($new->contact_id)->toBe($contact->id)
        ->and($new->status)->toBe(ConversationStatus::Pending)
        ->and($closed->messages()->count())->toBe(0)
        ->and($new->messages()->sole()->body)->toBe('Oi Maria');
});

it('files a WhatsApp Official send under the number Meta resolved, with the file and its name', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => '+55 11 98765-4321', 'wa_id' => '551187654321']],
        'messages' => [['id' => 'wamid.DOC1']],
    ])]);
    $owner = F::owner();
    $connection = inboxApiConnection($owner, Channel::WhatsappOfficial);

    inboxApiSend($owner, [
        'connection_id' => $connection->public_id,
        'to' => '+55 11 98765-4321',
        'type' => 'document',
        'media_url' => 'https://cdn.example.com/download?id=42',
        'filename' => 'Boleto março.pdf',
        'message' => 'Segue o boleto',
    ])->assertCreated();

    $conversation = Conversation::where('connection_id', $connection->id)->sole();
    $message = $conversation->messages()->sole();

    // The spelling the webhook will use when they answer, not the one typed.
    expect($conversation->external_id)->toBe('551187654321')
        ->and(Contact::sole()->external_id)->toBe('551187654321')
        ->and($message->external_id)->toBe('wamid.DOC1')
        ->and($message->message_type)->toBe(MessageType::Document)
        ->and($message->body)->toBe('Segue o boleto')
        ->and($message->attachment)->toBe('https://cdn.example.com/download?id=42')
        ->and($message->meta['filename'])->toBe('Boleto março.pdf');
});

it('records a message once even when the channel hands back the same id twice', function () {
    Http::fake(['*' => Http::response(apiwaySent('SAME-ID'))]);
    $owner = F::owner();
    $connection = inboxApiConnection($owner, Channel::WhatsappApiway);

    foreach ([1, 2] as $attempt) {
        inboxApiSend($owner, [
            'connection_id' => $connection->public_id,
            'phone' => '5511987654321',
            'message' => 'Oi',
        ])->assertCreated();
    }

    expect(Message::where('external_id', 'SAME-ID')->count())->toBe(1);
});

it('records nothing when the channel refused the message', function () {
    Http::fake(['*' => Http::response(['success' => false, 'error' => 'not connected'], 502)]);
    $owner = F::owner();
    $connection = inboxApiConnection($owner, Channel::WhatsappApiway);

    inboxApiSend($owner, [
        'connection_id' => $connection->public_id,
        'phone' => '5511987654321',
        'message' => 'Oi',
    ])->assertUnprocessable();

    expect(Contact::count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

it('still gives the flow its opening move when the customer answers an API message', function () {
    Http::fake(['*' => Http::response(apiwaySent('OUT-4'))]);
    $owner = F::owner();
    $connection = inboxApiConnection($owner, Channel::WhatsappApiway);

    inboxApiSend($owner, [
        'connection_id' => $connection->public_id,
        'phone' => '5511987654321',
        'message' => 'Seu acesso vence amanhã',
    ])->assertCreated();

    $conversation = Conversation::where('connection_id', $connection->id)->sole();

    $conversation->messages()->create([
        'external_id' => 'IN-1',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Como renovo?',
        'sent_at' => now()->addMinute(),
    ]);

    // Before these messages were recorded, this answer opened a thread of its
    // own and got the flow; it still does.
    expect(FlowRunner::openIfFirst($conversation->fresh()))->toBeTrue();

    // An agent having written from the panel is somebody else's conversation.
    $conversation->messages()->create([
        'external_id' => 'OUT-PANEL',
        'sender_type' => SenderType::Outgoing,
        'sent_by_user_id' => $owner->id,
        'message_type' => MessageType::Text,
        'body' => 'Posso ajudar?',
        'sent_at' => now(),
    ]);

    expect(FlowRunner::openIfFirst($conversation->fresh()))->toBeFalse();
});
