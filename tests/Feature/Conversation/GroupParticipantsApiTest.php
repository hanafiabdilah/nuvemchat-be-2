<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\GroupConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function participantsTenantUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    return $user;
}

function participantsConnection(User $user): Connection
{
    return Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::Telegram,
        'name' => 'Telegram',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => 'test-token'],
    ]);
}

function participantsGroup(Connection $connection, ConversationType $type = ConversationType::Group): Conversation
{
    $group = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '-100123',
        'channel' => $connection->channel,
        'name' => 'Time de Suporte',
        'is_group' => $type === ConversationType::Group,
    ]);

    return Conversation::create([
        'contact_id' => $group->id,
        'connection_id' => $connection->id,
        'external_id' => '-100123',
        'type' => $type,
        'status' => ConversationStatus::Pending,
    ]);
}

function participantsMember(Connection $connection, string $externalId, string $name): Contact
{
    return Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => $externalId,
        'channel' => $connection->channel,
        'name' => $name,
        'username' => strtolower($name),
    ]);
}

test('the endpoint lists every member who has written in the group, sorted by name', function () {
    $this->withoutMiddleware();
    $user = participantsTenantUser();
    $connection = participantsConnection($user);
    $conversation = participantsGroup($connection);

    $bob = participantsMember($connection, '2002', 'Bob');
    $alice = participantsMember($connection, '2001', 'Alice');
    GroupConversationService::addParticipant($conversation, $bob);
    GroupConversationService::addParticipant($conversation, $alice);

    Sanctum::actingAs($user);

    $this->getJson("/api/conversations/{$conversation->id}/participants")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alice')
        ->assertJsonPath('data.0.username', 'alice')
        ->assertJsonPath('data.1.name', 'Bob');
});

test('a private conversation reports no participants', function () {
    $this->withoutMiddleware();
    $user = participantsTenantUser();
    $connection = participantsConnection($user);
    $conversation = participantsGroup($connection, ConversationType::Private);

    Sanctum::actingAs($user);

    $this->getJson("/api/conversations/{$conversation->id}/participants")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the group contact itself is never listed as a participant', function () {
    $this->withoutMiddleware();
    $user = participantsTenantUser();
    $connection = participantsConnection($user);
    $conversation = participantsGroup($connection);

    GroupConversationService::addParticipant($conversation, $conversation->contact);

    Sanctum::actingAs($user);

    $this->getJson("/api/conversations/{$conversation->id}/participants")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('another tenant cannot read a group roster', function () {
    $this->withoutMiddleware();
    $owner = participantsTenantUser();
    $connection = participantsConnection($owner);
    $conversation = participantsGroup($connection);
    GroupConversationService::addParticipant($conversation, participantsMember($connection, '2001', 'Alice'));

    Sanctum::actingAs(participantsTenantUser());

    $this->getJson("/api/conversations/{$conversation->id}/participants")->assertNotFound();
});
