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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * Two agents looking at the same queue: the second click on Accept used to
 * come back as the raw English "Conversation is not pending", which the SPA
 * printed as-is. The refusal now carries a stable code the SPA words for the
 * agent, and a repeat click by the agent who already holds the thread is not
 * an error at all.
 */

function acceptRaceConnection(): Connection
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'Canal',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
}

function acceptRaceAgent(Connection $connection, string $name): User
{
    $agent = User::factory()->create(['tenant_id' => $connection->tenant_id, 'name' => $name]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);

    return $agent->fresh();
}

function acceptRaceConversation(Connection $connection, ?User $assignee, ConversationStatus $status): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '5511988887777',
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

test('accepting a thread a colleague already took is refused with a code the SPA can word', function () {
    Event::fake();
    $connection = acceptRaceConnection();
    $first = acceptRaceAgent($connection, 'Bruna');
    $late = acceptRaceAgent($connection, 'Carlos');
    $conversation = acceptRaceConversation($connection, $first, ConversationStatus::Active);

    $this->actingAs($late, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('code', 'conversation_already_accepted');

    // The colleague who clicked first keeps it, and nothing was written.
    expect((int) $conversation->fresh()->user_id)->toBe((int) $first->id)
        ->and($conversation->messages()->count())->toBe(0);
});

test('a second accept by the agent who already holds the thread is a quiet no-op', function () {
    Event::fake();
    $connection = acceptRaceConnection();
    $agent = acceptRaceAgent($connection, 'Bruna');
    $conversation = acceptRaceConversation($connection, $agent, ConversationStatus::Active);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/accept")
        ->assertOk();

    // No second "took this conversation" note for a double click.
    expect((int) $conversation->fresh()->user_id)->toBe((int) $agent->id)
        ->and($conversation->messages()->count())->toBe(0);
});

test('accepting a resolved thread is refused with its own code', function () {
    Event::fake();
    $connection = acceptRaceConnection();
    $holder = acceptRaceAgent($connection, 'Bruna');
    $other = acceptRaceAgent($connection, 'Carlos');
    $conversation = acceptRaceConversation($connection, $holder, ConversationStatus::Resolved);

    $this->actingAs($other, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/accept")
        ->assertStatus(400)
        ->assertJsonPath('code', 'conversation_not_pending');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Resolved);
});

test('a colleague who commits between the read and the lock keeps the thread', function () {
    // Only the read hook runs for real; broadcasts and observers stay faked
    // like in the rest of this file.
    Event::fakeExcept(['eloquent.retrieved: '.Conversation::class]);
    $connection = acceptRaceConnection();
    $winner = acceptRaceAgent($connection, 'Bruna');
    $late = acceptRaceAgent($connection, 'Carlos');
    $conversation = acceptRaceConversation($connection, null, ConversationStatus::Pending);

    // Bruna's accept lands right after Carlos's request has read the row as
    // Pending: the window in which both used to "win".
    $raced = false;
    Conversation::retrieved(function (Conversation $read) use (&$raced, $conversation, $winner) {
        if ($raced || $read->id !== $conversation->id) {
            return;
        }
        $raced = true;
        Conversation::whereKey($read->id)->update([
            'status' => ConversationStatus::Active->value,
            'user_id' => $winner->id,
        ]);
    });

    $this->actingAs($late, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('code', 'conversation_already_accepted');

    expect($raced)->toBeTrue()
        ->and((int) $conversation->fresh()->user_id)->toBe((int) $winner->id)
        ->and($conversation->messages()->count())->toBe(0);
});
