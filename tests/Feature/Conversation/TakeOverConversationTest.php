<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationTakenOver;
use App\Events\ConversationUpdated;
use App\Http\Controllers\Api\ConversationController;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function takeOverOwner(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function takeOverConnection(User $owner, Channel $channel = Channel::WhatsappApiway): Connection
{
    return Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => $channel,
        'name' => 'Canal',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
}

/** A plain agent of the same tenant, holding the given connection. */
function takeOverAgent(User $owner, Connection $connection, ?string $name = null): User
{
    $agent = User::factory()->create(array_filter([
        'tenant_id' => $owner->tenant_id,
        'name' => $name,
    ]));
    $agent->connections()->syncWithoutDetaching([$connection->id]);

    return $agent->fresh();
}

function takeOverConversation(Connection $connection, ?User $assignee, ConversationStatus $status = ConversationStatus::Active): Conversation
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
        'status' => $status,
    ]);
}

test('an agent can take an active conversation over from another agent', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $holder = takeOverAgent($owner, $connection);
    $taker = takeOverAgent($owner, $connection);
    $conversation = takeOverConversation($connection, $holder);

    // The precondition that makes this endpoint necessary: the taker cannot act
    // on the thread at all, so transfer() would refuse them.
    expect($conversation->isAccessibleBy($taker))->toBeFalse();

    $this->actingAs($taker, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertOk()
        ->assertJsonPath('data.agent.id', $taker->id);

    $conversation->refresh();

    expect((int) $conversation->user_id)->toBe((int) $taker->id)
        ->and($conversation->status)->toBe(ConversationStatus::Active)
        ->and($conversation->needs_human)->toBeFalsy()
        ->and($conversation->isAccessibleBy($taker))->toBeTrue();
});

test('taking over notifies the agent who lost the thread', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $holder = takeOverAgent($owner, $connection);
    $taker = takeOverAgent($owner, $connection);
    $conversation = takeOverConversation($connection, $holder);

    $this->actingAs($taker, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertOk();

    Event::assertDispatched(ConversationTakenOver::class, fn ($event) => $event->conversation->id === $conversation->id
        && $event->fromAgent->id === $holder->id
        && $event->toAgent->id === $taker->id);

    // State sync rides on the ordinary update event, same as transfer.
    Event::assertDispatched(ConversationUpdated::class, fn ($event) => $event->conversation->id === $conversation->id);
});

test('taking over writes an info note into the thread', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $holder = takeOverAgent($owner, $connection, 'Bruna');
    $taker = takeOverAgent($owner, $connection, 'Carlos');
    $conversation = takeOverConversation($connection, $holder);

    $this->actingAs($taker, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertOk();

    $note = $conversation->messages()->where('message_type', MessageType::Info)->sole();

    expect($note->body)->toBe('Carlos took over this conversation from Bruna.')
        ->and($note->meta['info']['code'])->toBe(ConversationController::INFO_TAKEN_OVER)
        ->and($note->meta['info']['params'])->toBe(['from' => 'Bruna', 'to' => 'Carlos'])
        // Never handed to the channel — the customer is told nothing.
        ->and($note->sender_type)->toBe(SenderType::Outgoing)
        ->and($note->external_id)->toBeNull();
});

test('taking over is written to the application log', function () {
    Event::fake();
    Log::spy();

    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $holder = takeOverAgent($owner, $connection);
    $taker = takeOverAgent($owner, $connection);
    $conversation = takeOverConversation($connection, $holder);

    $this->actingAs($taker, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertOk();

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context = []) => $message === 'Conversation taken over'
            && $context['conversation_id'] === $conversation->id
            && $context['actor_id'] === $taker->id
            && $context['from_user_id'] === $holder->id
            && $context['to_user_id'] === $taker->id)
        ->once();
});

test('an unassigned active thread is claimed without notifying anyone', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $taker = takeOverAgent($owner, $connection, 'Carlos');
    $conversation = takeOverConversation($connection, null);

    $this->actingAs($taker, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertOk();

    expect((int) $conversation->fresh()->user_id)->toBe((int) $taker->id);

    Event::assertNotDispatched(ConversationTakenOver::class);

    // The thread still records what happened — there was simply nobody to
    // take it from, so the note says so instead of naming a phantom agent.
    $note = $conversation->messages()->where('message_type', MessageType::Info)->sole();

    expect($note->body)->toBe('Carlos took this conversation.')
        ->and($note->meta['info']['code'])->toBe(ConversationController::INFO_ASSIGNED)
        ->and($note->meta['info']['params'])->toBe(['to' => 'Carlos']);
});

test('a queued or AI-handled thread is refused — that is what accept is for', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $taker = takeOverAgent($owner, $connection);

    foreach ([ConversationStatus::Pending, ConversationStatus::AiHandling, ConversationStatus::Resolved] as $status) {
        $conversation = takeOverConversation($connection, null, $status);

        $this->actingAs($taker, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/take-over")
            ->assertStatus(400);

        expect($conversation->fresh()->user_id)->toBeNull();
    }
});

test('taking over a conversation you already hold is refused', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $holder = takeOverAgent($owner, $connection);
    $conversation = takeOverConversation($connection, $holder);

    $this->actingAs($holder, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertStatus(400);
});

test('an e-mail thread has no assignee to take over', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner, Channel::Email);
    $taker = takeOverAgent($owner, $connection);
    $conversation = takeOverConversation($connection, null);

    $this->actingAs($taker, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertStatus(400);

    expect($conversation->fresh()->user_id)->toBeNull();
});

test('an agent without the connection cannot take the thread over', function () {
    Event::fake();
    $owner = takeOverOwner();
    $connection = takeOverConnection($owner);
    $holder = takeOverAgent($owner, $connection);
    $conversation = takeOverConversation($connection, $holder);

    // Same tenant, but no connection_user row: the thread is not in their inbox
    // at all, so it must not be claimable either.
    $outsider = User::factory()->create(['tenant_id' => $owner->tenant_id]);

    $this->actingAs($outsider, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertNotFound();

    expect((int) $conversation->fresh()->user_id)->toBe((int) $holder->id);
});

test('a conversation from another tenant cannot be taken over', function () {
    Event::fake();
    $owner = takeOverOwner();
    $stranger = takeOverOwner();
    $connection = takeOverConnection($stranger);
    $conversation = takeOverConversation($connection, null);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertNotFound();

    expect($conversation->fresh()->user_id)->toBeNull();
});
