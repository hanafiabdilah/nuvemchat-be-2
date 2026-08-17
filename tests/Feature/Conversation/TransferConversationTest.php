<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationTransferred;
use App\Http\Controllers\Api\ConversationController;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The tenant's owner. The role is what grants blanket connection access
 * (User::canAccessAllConnections) — owning the tenant row is not enough, and a
 * fixture without it describes an account the signup flow cannot produce.
 */
function transferTestOwner(string $name = 'Ana Owner'): User
{
    $user = User::factory()->create(['name' => $name]);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $user->assignRole(Role::findOrCreate('owner', 'web'));

    return $user->fresh();
}

function transferTestConnection(User $owner): Connection
{
    return Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'Canal',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
}

function transferTestAgent(User $owner, Connection $connection, string $name): User
{
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'name' => $name]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);

    return $agent->fresh();
}

function transferTestConversation(Connection $connection, ?User $assignee): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $assignee?->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Active,
    ]);
}

test('transferring writes an info note naming both agents', function () {
    Event::fake();
    $owner = transferTestOwner();
    $connection = transferTestConnection($owner);
    $holder = transferTestAgent($owner, $connection, 'Bruna');
    $target = transferTestAgent($owner, $connection, 'Carlos');
    $conversation = transferTestConversation($connection, $holder);

    $this->actingAs($holder, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/transfer", ['agent_id' => $target->id])
        ->assertOk();

    $note = $conversation->messages()->where('message_type', MessageType::Info)->sole();

    expect($note->body)->toBe('Bruna transferred this conversation to Carlos.')
        ->and($note->meta['info']['code'])->toBe(ConversationController::INFO_TRANSFERRED)
        ->and($note->meta['info']['params'])->toBe(['from' => 'Bruna', 'to' => 'Carlos'])
        // Outgoing keeps the note out of the unread badge, which counts only
        // incoming; the panel branches on message_type before direction.
        ->and($note->sender_type)->toBe(SenderType::Outgoing)
        // Nothing was handed to the channel: the customer sees no such message.
        ->and($note->external_id)->toBeNull();

    Event::assertDispatched(ConversationTransferred::class);
});

test('the note names whoever performed the transfer, not the former assignee', function () {
    Event::fake();
    $owner = transferTestOwner();
    $connection = transferTestConnection($owner);
    $holder = transferTestAgent($owner, $connection, 'Bruna');
    $target = transferTestAgent($owner, $connection, 'Carlos');
    $conversation = transferTestConversation($connection, $holder);

    // An owner may move a thread that was never theirs — naming Bruna as the
    // one who transferred it would be a lie.
    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/transfer", ['agent_id' => $target->id])
        ->assertOk();

    $note = $conversation->messages()->where('message_type', MessageType::Info)->sole();

    expect($note->meta['info']['params'])->toBe(['from' => $owner->name, 'to' => 'Carlos']);
});

test('transferring is written to the application log', function () {
    Event::fake();
    Log::spy();

    $owner = transferTestOwner();
    $connection = transferTestConnection($owner);
    $holder = transferTestAgent($owner, $connection, 'Bruna');
    $target = transferTestAgent($owner, $connection, 'Carlos');
    $conversation = transferTestConversation($connection, $holder);

    $this->actingAs($holder, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/transfer", ['agent_id' => $target->id])
        ->assertOk();

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'Conversation transferred'
            && $context['conversation_id'] === $conversation->id
            && $context['actor_id'] === $holder->id
            && $context['from_user_id'] === $holder->id
            && $context['to_user_id'] === $target->id
            && $context['tenant_id'] === $owner->tenant_id)
        ->once();
});

test('a refused transfer leaves no note behind', function () {
    Event::fake();
    $owner = transferTestOwner();
    $connection = transferTestConnection($owner);
    $holder = transferTestAgent($owner, $connection, 'Bruna');
    $conversation = transferTestConversation($connection, $holder);

    // Same tenant, no connection_user row: the transfer is refused, so the
    // thread must not carry a note claiming it moved.
    $stranded = User::factory()->create(['tenant_id' => $owner->tenant_id, 'name' => 'Dani']);

    $this->actingAs($holder, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/transfer", ['agent_id' => $stranded->id])
        ->assertStatus(422);

    expect($conversation->messages()->where('message_type', MessageType::Info)->exists())->toBeFalse()
        ->and((int) $conversation->fresh()->user_id)->toBe((int) $holder->id);
});
