<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The chat thread draws an avatar beside the first message of each sender run,
 * so `sender.contact` has to carry its own photo — in a group the
 * conversation's contact is the group, not the person who wrote the message.
 */
function senderResourceConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Telegram,
        'name' => 'Telegram',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => 'test-token'],
    ]);
}

function senderResourceGroupMessage(Connection $connection, ?string $photoPath): Message
{
    $group = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '-100777',
        'channel' => $connection->channel,
        'name' => 'Time de Suporte',
        'is_group' => true,
    ]);

    $member = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '9001',
        'channel' => $connection->channel,
        'name' => 'Alice',
        'username' => 'alice',
        'photo_profile' => $photoPath,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $group->id,
        'connection_id' => $connection->id,
        'external_id' => '-100777',
        'type' => ConversationType::Group,
        'status' => ConversationStatus::Pending,
    ]);

    return $conversation->messages()->create([
        'external_id' => 'm-1',
        'contact_id' => $member->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Olá',
        'sent_at' => now(),
    ]);
}

test('a group message sender carries the member photo url', function () {
    Storage::fake('local');
    $connection = senderResourceConnection();
    $message = senderResourceGroupMessage($connection, 'profile_photos/9001_abc.jpg');

    $payload = MessageResource::make($message->fresh())->toArray(request());

    expect($payload['sender']['source'])->toBe('contact')
        ->and($payload['sender']['contact']['name'])->toBe('Alice')
        ->and($payload['sender']['contact']['username'])->toBe('alice')
        ->and($payload['sender']['contact']['photo_profile_url'])->toBeString()
        ->and($payload['sender']['contact']['photo_profile_url'])->toContain('profile_photos/9001_abc.jpg');
});

test('a member without a photo reports a null url rather than omitting the key', function () {
    Storage::fake('local');
    $connection = senderResourceConnection();
    $message = senderResourceGroupMessage($connection, null);

    $payload = MessageResource::make($message->fresh())->toArray(request());

    expect($payload['sender']['contact'])->toHaveKey('photo_profile_url')
        ->and($payload['sender']['contact']['photo_profile_url'])->toBeNull();
});

test('a private incoming message still reports no sender at all', function () {
    Storage::fake('local');
    $connection = senderResourceConnection();

    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '9002',
        'channel' => $connection->channel,
        'name' => 'Bob',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '9002',
        'status' => ConversationStatus::Pending,
    ]);

    $message = $conversation->messages()->create([
        'external_id' => 'm-2',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    // The FE falls back to the conversation's contact for the 1:1 avatar, so
    // nothing needs to be added here — but it must stay null, not become an
    // empty 'contact' shape.
    expect(MessageResource::make($message->fresh())->toArray(request())['sender'])->toBeNull();
});
