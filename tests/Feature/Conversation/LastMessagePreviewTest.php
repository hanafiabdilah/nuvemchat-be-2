<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\SystemMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * What the conversation list previews.
 *
 * System notes are messages — they take their place in the timeline and reach
 * every tab over the usual broadcast — but nobody said them, and the preview
 * line exists to show what was said. Accepting a thread, transferring it,
 * resolving it and a missed call each write one, so before this the row an
 * agent scans read "Ana assumiu esta conversa." far more often than it read the
 * customer. The one thread that keeps its note is the one that has nothing else.
 */
function previewUser(): User
{
    $user = User::factory()->create(['name' => 'Ana Souza']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function previewThread(User $user): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
    ]);

    // Agents reach an inbox through connection_user; a fixture that skips
    // the grant describes an account the signup flow cannot produce.
    $user->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => '55119'.fake()->unique()->numerify('#######'),
        'name' => 'João Pereira',
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
    ]);
}

function previewIncoming(Conversation $conversation, string $body, ?Carbon $at = null): Message
{
    return $conversation->messages()->create([
        'external_id' => (string) Str::uuid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => $at ?? now(),
    ]);
}

test('the list previews the last real message, not the note written after it', function () {
    $user = previewUser();
    $conversation = previewThread($user);

    previewIncoming($conversation, 'Meu pedido chegou quebrado', now()->subMinutes(5));
    $note = SystemMessage::info($conversation, 'Ana Souza took over this conversation.', 'conversation_taken_over', ['agent' => 'Ana Souza']);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/conversations')->assertOk();

    $response->assertJsonPath('data.0.last_message.body', 'Meu pedido chegou quebrado')
        ->assertJsonPath('data.0.last_message.message_type', MessageType::Text->value);

    // The note still moves the thread up the list — the server bumps
    // last_message_at for it, and a row that stayed put here would jump on the
    // next reload. Only the text it shows is the customer's.
    expect($response->json('data.0.last_message_at'))->toBe($note->sent_at);
});

test('a thread that holds nothing but notes still previews the note', function () {
    $user = previewUser();
    $conversation = previewThread($user);

    // A missed call from a number that never wrote opens exactly this thread.
    SystemMessage::info($conversation, 'Missed call.', 'call_missed');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.last_message.message_type', MessageType::Info->value)
        ->assertJsonPath('data.0.last_message.meta.info.code', 'call_missed');
});

test('the accessor skips notes whether or not the relation was eager-loaded', function () {
    $user = previewUser();
    $conversation = previewThread($user);

    $message = previewIncoming($conversation, 'Bom dia');
    SystemMessage::info($conversation, 'Conversation resolved.', 'conversation_status_changed', [
        'from_status' => ConversationStatus::Pending->value,
        'to_status' => ConversationStatus::Resolved->value,
    ]);

    // Lazy: the webhook handlers read it off a freshly loaded model.
    expect($conversation->fresh()->last_message->id)->toBe($message->id);

    // Eager: how the sync page loads a whole page of rows at once.
    $eager = Conversation::with(['lastMessage', 'lastInfoMessage'])->find($conversation->id);
    expect($eager->last_message->id)->toBe($message->id);
});

test('the accessor falls back to the note when the eager-loaded preview is empty', function () {
    $user = previewUser();
    $conversation = previewThread($user);

    $note = SystemMessage::info($conversation, 'Missed call.', 'call_missed');

    $eager = Conversation::with(['lastMessage', 'lastInfoMessage'])->find($conversation->id);

    expect($eager->getRelation('lastMessage'))->toBeNull()
        ->and($eager->last_message->id)->toBe($note->id);

    // And with nothing loaded at all — how a broadcast serializes the row.
    expect($conversation->fresh()->last_message->id)->toBe($note->id);
});
