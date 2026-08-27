<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\ExpiredWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Every status change leaves a note in the thread.
 *
 * Hooked to the model rather than to each call site, because there is no single
 * place a status changes — an agent accepting or resolving, a bulk update, a
 * reply window closing, and whatever adds the next one. These tests are mostly
 * about the two rules that make it readable: the note names whoever is driving
 * the request when there is one, and it stands down where a more specific note
 * already covers the same instant.
 */
function statusNoteOwner(): User
{
    $user = User::factory()->create(['name' => 'Ana Souza']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    $role->givePermissionTo(Permission::findOrCreate('conversations.view', 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

function statusNoteThread(User $user, array $attributes = []): Conversation
{
    $connection = Connection::firstOrCreate(
        ['tenant_id' => $user->tenant_id, 'name' => 'Vendas'],
        ['channel' => Channel::WhatsappOfficial, 'status' => ConnectionStatus::Active],
    );

    $user->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => '55119'.fake()->unique()->numerify('#######'),
        'name' => 'João Pereira',
        'channel' => $connection->channel,
    ]);

    return Conversation::create(array_merge([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => Status::Pending,
        'last_message_at' => now(),
    ], $attributes));
}

/** @return list<array{code: ?string, params: array}> */
function statusNotes(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->orderBy('id')
        ->get()
        ->map(fn (Message $message) => [
            'code' => $message->meta['info']['code'] ?? null,
            'params' => $message->meta['info']['params'] ?? [],
        ])
        ->all();
}

it('records a resolution as a status change, naming the agent who did it', function () {
    $user = statusNoteOwner();
    $conversation = statusNoteThread($user, ['status' => Status::Active, 'user_id' => $user->id]);

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/resolve")->assertOk();

    expect(statusNotes($conversation))->toBe([[
        'code' => 'conversation_status_changed_by',
        'params' => ['from_status' => 'active', 'to_status' => 'resolved', 'by' => 'Ana Souza'],
    ]]);
});

it('writes the note as a real thread message that is never sent to the channel', function () {
    $user = statusNoteOwner();
    $conversation = statusNoteThread($user, ['status' => Status::Active, 'user_id' => $user->id]);

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/resolve")->assertOk();

    $note = Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->sole();

    // Outgoing keeps it out of the unread badge, which only counts Incoming;
    // the chat panel branches on message_type long before it looks at
    // direction, so it is never drawn as an agent's bubble.
    expect($note->sender_type)->toBe(SenderType::Outgoing)
        ->and($note->external_id)->toBeNull()
        // The English body is the fallback for a client that does not know the
        // code yet — it has to read on its own.
        ->and($note->body)->toBe('Ana Souza changed the status from active to resolved.');
});

it('does not add a second note when accepting already wrote a better one', function () {
    $user = statusNoteOwner();
    $conversation = statusNoteThread($user);

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/accept")->assertOk();

    // "Ana took this conversation" names the person as well as implying
    // pending → active. A transition note underneath it is a line and no
    // information.
    expect(statusNotes($conversation))->toBe([[
        'code' => 'conversation_assigned',
        'params' => ['to' => 'Ana Souza'],
    ]]);
});

it('does not add a second note when a reply window closed the thread', function () {
    $user = statusNoteOwner();
    $conversation = statusNoteThread($user, ['status' => Status::Active, 'user_id' => $user->id]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now()->subHours(30),
    ]);

    expect(ExpiredWindowResolver::resolve($conversation->fresh()))->toBeTrue();

    expect(statusNotes($conversation))->toBe([[
        'code' => 'messaging_window_expired',
        'params' => ['hours' => 24],
    ]]);
});

it('says so without a subject when nobody is signed in', function () {
    $user = statusNoteOwner();
    $conversation = statusNoteThread($user, ['status' => Status::Active]);

    // A queue worker, a webhook, a scheduled command: the absence of an actor
    // is the honest answer, and it needs a different sentence rather than one
    // with a blank in it.
    $conversation->update(['status' => Status::Resolved]);

    expect(statusNotes($conversation))->toBe([[
        'code' => 'conversation_status_changed',
        'params' => ['from_status' => 'active', 'to_status' => 'resolved'],
    ]]);
});

it('stays quiet when a save changed something other than the status', function () {
    $user = statusNoteOwner();
    $conversation = statusNoteThread($user, ['status' => Status::Active]);

    $conversation->update(['muted_at' => now()]);
    $conversation->update(['status' => Status::Active]);

    expect(statusNotes($conversation))->toBe([]);
});

it('records every step when a thread is closed in bulk', function () {
    $user = statusNoteOwner();
    $first = statusNoteThread($user, ['status' => Status::Active, 'user_id' => $user->id]);
    $second = statusNoteThread($user, ['status' => Status::Active, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/conversations/bulk-status', [
            'ids' => [$first->id, $second->id],
            'status' => Status::Resolved->value,
        ])
        ->assertOk();

    foreach ([$first, $second] as $conversation) {
        expect(statusNotes($conversation))->toBe([[
            'code' => 'conversation_status_changed_by',
            'params' => ['from_status' => 'active', 'to_status' => 'resolved', 'by' => 'Ana Souza'],
        ]]);
    }
});
