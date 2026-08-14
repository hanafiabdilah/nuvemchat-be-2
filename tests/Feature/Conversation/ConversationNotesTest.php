<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Tenant;
use App\Models\User;
use App\Events\ConversationUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function noteTestOwner(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    // The role is what `isEditableBy` and `canAccessAllConnections` read —
    // owning the tenant row alone is not the same thing.
    Role::findOrCreate('owner', 'web');
    $user->assignRole('owner');

    return $user->fresh();
}

function noteTestConversation(User $user, ConversationStatus $status = ConversationStatus::Pending): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    // Agents reach an inbox through connection_user; a fixture that skips the
    // grant describes an account the signup flow cannot produce.
    $user->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => $status,
    ]);
}

test('a note can be written, listed, edited and deleted', function () {
    $user = noteTestOwner();
    $conversation = noteTestConversation($user);

    $created = $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/notes", ['body' => '  Prometi retorno até sexta.  '])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Prometi retorno até sexta.')
        ->assertJsonPath('data.author.name', $user->name)
        ->assertJsonPath('data.can_edit', true)
        ->json('data.id');

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/notes")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $created);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/conversations/{$conversation->id}/notes/{$created}", ['body' => 'Retorno feito.'])
        ->assertOk()
        ->assertJsonPath('data.body', 'Retorno feito.');

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/conversations/{$conversation->id}/notes/{$created}")
        ->assertOk();

    expect(ConversationNote::count())->toBe(0);
});

test('an empty note is refused', function () {
    $user = noteTestOwner();
    $conversation = noteTestConversation($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/notes", ['body' => '   '])
        ->assertStatus(422);
});

test('notes are listed newest first', function () {
    $user = noteTestOwner();
    $conversation = noteTestConversation($user);

    $older = $conversation->notes()->create(['user_id' => $user->id, 'body' => 'primeira']);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = $conversation->notes()->create(['user_id' => $user->id, 'body' => 'segunda']);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/notes")
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);
});

test('notes belong to their own thread, not to the contact', function () {
    $user = noteTestOwner();
    $first = noteTestConversation($user);

    // The same customer coming back weeks later opens a *new* thread; a note
    // about the old one must not follow them into it.
    $second = Conversation::create([
        'contact_id' => $first->contact_id,
        'connection_id' => $first->connection_id,
        'external_id' => $first->external_id,
        'status' => ConversationStatus::Active,
    ]);

    $first->notes()->create(['user_id' => $user->id, 'body' => 'sobre a primeira conversa']);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/conversations/{$second->id}/notes")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('an agent without the connection reaches neither the list nor the note', function () {
    $owner = noteTestOwner();
    $conversation = noteTestConversation($owner);
    $note = $conversation->notes()->create(['user_id' => $owner->id, 'body' => 'interno']);

    $stranger = User::factory()->create(['tenant_id' => $owner->tenant_id]);

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/notes")
        ->assertNotFound();

    $this->actingAs($stranger, 'sanctum')
        ->deleteJson("/api/conversations/{$conversation->id}/notes/{$note->id}")
        ->assertNotFound();
});

test('an unassigned thread is annotatable by any member holding the connection', function () {
    $owner = noteTestOwner();
    $conversation = noteTestConversation($owner);

    // Not the assignee (there is none), not an owner — the queue is exactly
    // where a note like "cliente já ligou duas vezes" earns its keep.
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$conversation->connection_id]);

    expect($conversation->isAccessibleBy($agent))->toBeFalse();

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/notes", ['body' => 'cliente já ligou duas vezes'])
        ->assertCreated();
});

test("someone else's note is read-only for a plain agent, but an owner may remove it", function () {
    $owner = noteTestOwner();
    $conversation = noteTestConversation($owner);

    $author = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $author->connections()->syncWithoutDetaching([$conversation->connection_id]);
    $other = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $other->connections()->syncWithoutDetaching([$conversation->connection_id]);

    $note = $conversation->notes()->create(['user_id' => $author->id, 'body' => 'anotação do autor']);

    $this->actingAs($other, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/notes")
        ->assertOk()
        ->assertJsonPath('data.0.can_edit', false);

    $this->actingAs($other, 'sanctum')
        ->putJson("/api/conversations/{$conversation->id}/notes/{$note->id}", ['body' => 'editada'])
        ->assertForbidden();

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/conversations/{$conversation->id}/notes/{$note->id}")
        ->assertOk();
});

test('the thread reports how many notes it carries, and says so as they change', function () {
    Event::fake();
    $user = noteTestOwner();
    $conversation = noteTestConversation($user);

    // The list skips threads that have never carried a message, so give this
    // one something to be listed by.
    $conversation->messages()->create([
        'external_id' => 'wamid.notes',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now(),
    ]);

    // Sync pages count with the query; a single conversation counts per row.
    // Both have to answer, or the marker on the list would blink out on the
    // next broadcast.
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.notes_count', 0);

    $noteId = $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/notes", ['body' => 'nota'])
        ->assertCreated()
        ->json('data.id');

    Event::assertDispatched(ConversationUpdated::class, fn ($event) => $event->conversation->id === $conversation->id
        && $event->conversation->notes_count === 1);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.notes_count', 1);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/conversations/{$conversation->id}/notes/{$noteId}")
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.notes_count', 0);
});
