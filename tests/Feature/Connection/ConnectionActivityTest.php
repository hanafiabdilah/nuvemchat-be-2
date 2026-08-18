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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake());

function activityFixture(Channel $channel = Channel::WhatsappApiway, ConnectionStatus $status = ConnectionStatus::Active): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $role = Role::findOrCreate('owner', 'web');
    $role->givePermissionTo(Permission::findOrCreate('connections.create', 'web'));
    $owner->assignRole('owner');

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Suporte',
        'color' => '#22c55e',
        'status' => $status,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'tok'],
        'accept_message' => 'Olá, sou {{agent_name}}',
        'closing_message' => 'Até logo',
        'service_hours' => ['enabled' => true],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511999999999',
        'name' => 'Maria',
        'channel' => $channel,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Pending,
        'last_message_at' => now(),
    ]);

    return [$tenant->fresh(), $owner->fresh(), $connection->fresh(), $conversation];
}

function activityMessage(Conversation $conversation, array $attributes = []): Message
{
    return Message::create(array_merge([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now(),
    ], $attributes));
}

test('health counts sends, failures and delivery for a channel that confirms it', function () {
    [, $owner, $connection, $conversation] = activityFixture(Channel::WhatsappApiway);

    activityMessage($conversation, ['sender_type' => SenderType::Incoming, 'body' => 'Quero saber dos planos']);
    activityMessage($conversation, ['delivery_at' => now()]);
    activityMessage($conversation, ['delivery_at' => now()]);
    activityMessage($conversation, ['error' => 'Token expirado']);

    Sanctum::actingAs($owner);
    $health = $this->getJson("/api/connections/{$connection->id}/activity")
        ->assertOk()
        ->json('data.health');

    expect($health['sent'])->toBe(3);
    expect($health['received'])->toBe(1);
    expect($health['failed'])->toBe(1);
    // 1 of 3 outbound refused — over the 5% line, so the drawer says broken.
    expect($health['failure_rate'])->toBe(33.3);
    expect($health['verdict'])->toBe('critical');
    expect($health['last_error']['message'])->toBe('Token expirado');

    expect($health['reports_delivery'])->toBeTrue();
    expect($health['delivered'])->toBe(2);
    expect($health['delivery_rate'])->toBe(66.7);
});

test('delivery is omitted, not zeroed, on a channel that never confirms it', function () {
    [, $owner, $connection, $conversation] = activityFixture(Channel::Telegram);

    activityMessage($conversation);
    activityMessage($conversation);

    Sanctum::actingAs($owner);
    $health = $this->getJson("/api/connections/{$connection->id}/activity")
        ->assertOk()
        ->json('data.health');

    // A zero here would read as "nothing ever arrives", which is the opposite
    // of what silence from Telegram means.
    expect($health['reports_delivery'])->toBeFalse();
    expect($health['delivered'])->toBeNull();
    expect($health['delivery_rate'])->toBeNull();
    expect($health['verdict'])->toBe('excellent');
});

test('a quiet connection is idle and a disconnected one is offline, never excellent', function () {
    [, $owner, $quiet] = activityFixture(Channel::WhatsappApiway);

    Sanctum::actingAs($owner);
    expect($this->getJson("/api/connections/{$quiet->id}/activity")->assertOk()->json('data.health.verdict'))
        ->toBe('idle');

    $quiet->update(['status' => ConnectionStatus::Inactive]);
    expect($this->getJson("/api/connections/{$quiet->id}/activity")->assertOk()->json('data.health.verdict'))
        ->toBe('offline');
});

test('the sparkline has one bucket per day including the days with no traffic', function () {
    [, $owner, $connection, $conversation] = activityFixture();

    activityMessage($conversation);

    Sanctum::actingAs($owner);
    $daily = $this->getJson("/api/connections/{$connection->id}/activity?days=7")
        ->assertOk()
        ->json('data.health.daily');

    // Zero-filled: drawn only from days that had traffic, a two-day outage
    // would render as an unbroken line.
    expect($daily)->toHaveCount(7);
    expect(collect($daily)->pluck('date')->unique())->toHaveCount(7);
    expect(collect($daily)->sum('sent'))->toBe(1);
});

test('events name what happened, and a failed send leads with the reason', function () {
    [, $owner, $connection, $conversation] = activityFixture();

    activityMessage($conversation, ['sender_type' => SenderType::Incoming, 'body' => 'Bom dia']);
    activityMessage($conversation, ['sent_by_flow_id' => null, 'body' => 'resposta do fluxo']);
    activityMessage($conversation, ['error' => 'Recipient not found', 'body' => 'texto que nao foi']);

    Sanctum::actingAs($owner);
    $events = $this->getJson("/api/connections/{$connection->id}/activity")
        ->assertOk()
        ->json('data.events');

    // Newest first.
    expect($events[0]['type'])->toBe('send_failed');
    expect($events[0]['preview'])->toBe('Recipient not found');
    expect($events[0]['contact'])->toBe('Maria');

    expect($events[2]['type'])->toBe('message_received');
    expect($events[2]['preview'])->toBe('Bom dia');
});

test('an agent cannot read the activity of a connection they do not hold', function () {
    [$tenant, , $connection] = activityFixture();

    $agent = User::factory()->create(['tenant_id' => $tenant->id]);

    Sanctum::actingAs($agent->fresh());
    $this->getJson("/api/connections/{$connection->id}/activity")->assertForbidden();

    $agent->connections()->sync([$connection->id]);
    Sanctum::actingAs($agent->fresh());
    $this->getJson("/api/connections/{$connection->id}/activity")->assertOk();
});

test('duplicating copies the settings but never the credentials', function () {
    [, $owner, $connection] = activityFixture();

    Sanctum::actingAs($owner);
    $copy = $this->postJson("/api/connections/{$connection->id}/duplicate")
        ->assertCreated()
        ->json('data');

    expect($copy['name'])->toBe('Suporte (2)');
    expect($copy['color'])->toBe('#22c55e');
    expect($copy['channel'])->toBe($connection->channel->value);
    expect($copy['automated_messages']['accept_message'])->toBe('Olá, sou {{agent_name}}');

    // The copy must not answer the original's webhooks: same credentials would
    // mean two connections racing over one number's threads.
    expect($copy['credentials'])->toBeNull();
    expect($copy['status'])->toBe(ConnectionStatus::Inactive->value);

    $stored = Connection::find($copy['id']);
    expect($stored->credentials)->toBeNull();
    expect($stored->service_hours)->toBe(['enabled' => true]);
});

test('duplicating twice does not produce two rows with the same name', function () {
    [, $owner, $connection] = activityFixture();

    Sanctum::actingAs($owner);
    $first = $this->postJson("/api/connections/{$connection->id}/duplicate")->assertCreated()->json('data.name');
    $second = $this->postJson("/api/connections/{$connection->id}/duplicate")->assertCreated()->json('data.name');

    expect($first)->toBe('Suporte (2)');
    expect($second)->toBe('Suporte (3)');

    // And duplicating the copy keeps counting instead of nesting suffixes.
    $copy = Connection::where('name', 'Suporte (3)')->firstOrFail();
    expect($this->postJson("/api/connections/{$copy->id}/duplicate")->assertCreated()->json('data.name'))
        ->toBe('Suporte (4)');
});
