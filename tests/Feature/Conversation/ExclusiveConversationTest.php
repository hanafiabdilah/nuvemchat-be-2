<?php

use App\Broadcasting\Channels;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * An exclusive conversation stays in every agent's inbox list, but only its
 * handler and the workspace's owners can read it — over HTTP and over the
 * realtime channels.
 */

function exclusiveWorld(): array
{
    $owner = User::factory()->create(['name' => 'Olívia Dona']);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $owner->assignRole(Role::findOrCreate('owner', 'web'));

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'Financeiro',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    $handler = exclusiveAgent($connection, 'Hana');
    $colleague = exclusiveAgent($connection, 'Caio');

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511988887777',
        'name' => 'Parceiro',
        'channel' => $connection->channel,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $handler->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Active,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'proposta de comissao confidencial',
        'sent_at' => now(),
    ]);

    return [$owner->fresh(), $connection, $handler, $colleague, $conversation->fresh()];
}

function exclusiveAgent(Connection $connection, string $name): User
{
    $agent = User::factory()->create(['tenant_id' => $connection->tenant_id, 'name' => $name]);
    $agent->connections()->sync([$connection->id]);
    $agent->givePermissionTo(Permission::findOrCreate('conversations.take-over', 'web'));
    $agent->givePermissionTo(Permission::findOrCreate('conversations.transfer', 'web'));

    return $agent->fresh();
}

test('the handler can hide a conversation and show it again', function () {
    [, , $handler, , $conversation] = exclusiveWorld();

    $this->actingAs($handler)->postJson("/api/conversations/{$conversation->id}/exclusive")
        ->assertOk()
        ->assertJsonPath('data.exclusive', true)
        ->assertJsonPath('data.exclusive_masked', false);

    expect($conversation->fresh()->exclusive_by_user_id)->toBe($handler->id)
        ->and($conversation->messages()->where('message_type', MessageType::Info)->count())->toBe(1);

    $this->actingAs($handler)->deleteJson("/api/conversations/{$conversation->id}/exclusive")
        ->assertOk()
        ->assertJsonPath('data.exclusive', false);

    expect($conversation->fresh()->exclusive_at)->toBeNull();
});

test('only the handler or an owner may change it', function () {
    [$owner, , , $colleague, $conversation] = exclusiveWorld();

    $this->actingAs($colleague)->postJson("/api/conversations/{$conversation->id}/exclusive")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_conversation_agent');

    $this->actingAs($owner)->postJson("/api/conversations/{$conversation->id}/exclusive")->assertOk();
});

test('groups and e-mail cannot be exclusive', function () {
    [, $connection, $handler, , $conversation] = exclusiveWorld();

    $conversation->update(['type' => ConversationType::Group]);

    $this->actingAs($handler)->postJson("/api/conversations/{$conversation->id}/exclusive")
        ->assertStatus(422)
        ->assertJsonPath('code', 'exclusive_not_supported');

    $conversation->update(['type' => ConversationType::Private]);
    $connection->update(['channel' => Channel::Email]);

    $this->actingAs($handler)->postJson("/api/conversations/{$conversation->id}/exclusive")
        ->assertStatus(422);
});

test('other agents keep the row in their list but not its content', function () {
    [, , , $colleague, $conversation] = exclusiveWorld();
    $conversation->update(['exclusive_at' => now()]);

    $row = collect($this->actingAs($colleague)->getJson('/api/conversations')->json('data'))
        ->firstWhere('id', $conversation->id);

    expect($row)->not->toBeNull()
        ->and($row['exclusive'])->toBeTrue()
        ->and($row['exclusive_masked'])->toBeTrue()
        ->and($row['last_message']['body'])->toBeNull()
        ->and($row['agent']['name'])->toBe('Hana');

    $this->actingAs($colleague)->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertForbidden()
        ->assertJsonPath('code', 'conversation_exclusive')
        ->assertJsonPath('agent', 'Hana');

    $this->actingAs($colleague)->getJson("/api/conversations/{$conversation->id}/variables")->assertForbidden();
    $this->actingAs($colleague)->getJson("/api/conversations/{$conversation->id}/read")->assertForbidden();

    $synced = collect($this->actingAs($colleague)->getJson('/api/messages')->json('data'))->pluck('body');
    expect($synced)->not->toContain('proposta de comissao confidencial');

    $found = $this->actingAs($colleague)->getJson('/api/messages/search?q=comissao')->json('data');
    expect($found)->toBeEmpty();
});

test('the handler and the owners still read everything', function () {
    [$owner, , $handler, , $conversation] = exclusiveWorld();
    $conversation->update(['exclusive_at' => now()]);

    foreach ([$handler, $owner] as $reader) {
        $row = collect($this->actingAs($reader)->getJson('/api/conversations')->json('data'))
            ->firstWhere('id', $conversation->id);

        expect($row['exclusive_masked'])->toBeFalse()
            ->and($row['last_message']['body'])->toBe('proposta de comissao confidencial');

        $bodies = collect($this->actingAs($reader)->getJson("/api/conversations/{$conversation->id}/messages")
            ->assertOk()->json('data'))->pluck('body');

        expect($bodies)->toContain('proposta de comissao confidencial');
    }
});

test('a colleague cannot take an exclusive conversation over or reopen it', function () {
    [, , $handler, $colleague, $conversation] = exclusiveWorld();
    $conversation->update(['exclusive_at' => now()]);

    $this->actingAs($colleague)->postJson("/api/conversations/{$conversation->id}/take-over")
        ->assertForbidden()
        ->assertJsonPath('code', 'conversation_exclusive');

    expect($conversation->fresh()->user_id)->toBe($handler->id);

    $conversation->update(['status' => ConversationStatus::Resolved, 'resolved_at' => now()]);

    $this->actingAs($colleague)->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertForbidden()
        ->assertJsonPath('code', 'conversation_exclusive');
});

test('a transfer hands the history to the new handler and away from the old one', function () {
    [, , $handler, $colleague, $conversation] = exclusiveWorld();
    $conversation->update(['exclusive_at' => now()]);

    $this->actingAs($handler)
        ->postJson("/api/conversations/{$conversation->id}/transfer", ['agent_id' => $colleague->id])
        ->assertOk();

    $conversation->refresh()->load('connection');

    expect($conversation->isExclusive())->toBeTrue()
        ->and($conversation->isReadableBy($colleague))->toBeTrue()
        ->and($conversation->isReadableBy($handler))->toBeFalse();
});

test('message events of an exclusive conversation go only to its readers', function () {
    [$owner, $connection, $handler, , $conversation] = exclusiveWorld();
    $conversation->update(['exclusive_at' => now()]);

    $message = $conversation->messages()->first();
    $channels = collect((new MessageReceived($message))->broadcastOn())->map->name;

    expect($channels->all())->toEqualCanonicalizing([
        'private-App.Models.User.'.$owner->id,
        'private-App.Models.User.'.$handler->id,
    ])->not->toContain(Channels::connection($connection->tenant_id, $connection->id)->name);

    // The shared-channel update is always masked, whoever triggered it.
    $this->actingAs($handler);
    $payload = (new ConversationUpdated($conversation->fresh()))->broadcastWith();

    expect($payload['exclusive_masked'])->toBeTrue()
        ->and($payload['last_message']['body'])->toBeNull()
        ->and($payload['flow_state'])->toBeNull();
});

test('a visible conversation still broadcasts on the connection channel', function () {
    [, $connection, , , $conversation] = exclusiveWorld();

    $channels = collect((new MessageReceived($conversation->messages()->first()))->broadcastOn())->map->name;

    expect($channels->all())->toBe([Channels::connection($connection->tenant_id, $connection->id)->name]);
});
