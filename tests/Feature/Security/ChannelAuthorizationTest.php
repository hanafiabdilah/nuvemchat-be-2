<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Events\ConnectionUpdated;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Every realtime channel is private now. These tests exercise the real
 * /api/broadcasting/auth endpoint rather than the callbacks directly, because
 * the bug being guarded against was not a wrong callback — there were no
 * callbacks at all, and the events rode a public channel that Reverb handed to
 * anyone who asked.
 */
beforeEach(function () {
    // The auth endpoint delegates to the configured broadcaster, and the null
    // driver (the test default) never consults a channel callback at all.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // Broadcast::channel() registers on whichever driver is current, and
    // routes/channels.php already ran at boot against the null driver. Swapping
    // the default above produces a fresh driver with no callbacks on it — which
    // denies everything, negative tests included, so it would look like a pass.
    // Re-run the registrations against the driver these tests actually use.
    require base_path('routes/channels.php');
});

function chanTenant(string $email): array
{
    $owner = User::factory()->create(['email' => $email]);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    Role::findOrCreate('owner', 'web');
    $owner->assignRole('owner');

    return [$tenant->fresh(), $owner->fresh()];
}

function chanConnection(Tenant $tenant, string $name = 'WhatsApp'): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => $name,
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'tok'],
    ]);
}

function chanAgent(Tenant $tenant, array $connectionIds = []): User
{
    $agent = User::factory()->create(['tenant_id' => $tenant->id]);
    $agent->connections()->sync($connectionIds);

    return $agent->fresh();
}

/** POST the subscription request Echo would send. */
function authorize(?User $user, string $channel)
{
    if ($user) {
        Sanctum::actingAs($user);
    }

    return test()->postJson('/api/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channel,
    ]);
}

test('an anonymous subscriber is refused', function () {
    [$tenant] = chanTenant('owner@a.test');
    $connection = chanConnection($tenant);

    authorize(null, "private-tenant.{$tenant->id}.connection.{$connection->id}")
        ->assertUnauthorized();

    authorize(null, "private-tenant-channel.{$tenant->id}")
        ->assertUnauthorized();
});

test('an agent cannot subscribe to a connection they were not given', function () {
    [$tenant] = chanTenant('owner@b.test');
    $held = chanConnection($tenant, 'Held');
    $other = chanConnection($tenant, 'Other');

    $agent = chanAgent($tenant, [$held->id]);

    authorize($agent, "private-tenant.{$tenant->id}.connection.{$other->id}")
        ->assertForbidden();
});

test('an agent can subscribe to a connection they hold', function () {
    [$tenant] = chanTenant('owner@c.test');
    $held = chanConnection($tenant, 'Held');
    $agent = chanAgent($tenant, [$held->id]);

    authorize($agent, "private-tenant.{$tenant->id}.connection.{$held->id}")
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('an owner reaches every connection of their tenant without pivot rows', function () {
    [$tenant, $owner] = chanTenant('owner@d.test');
    $connection = chanConnection($tenant);

    expect($owner->connections()->count())->toBe(0);

    authorize($owner, "private-tenant.{$tenant->id}.connection.{$connection->id}")
        ->assertOk();
});

test('a tenant member cannot subscribe to another tenant', function () {
    [$mine, $owner] = chanTenant('owner@e.test');
    [$theirs] = chanTenant('owner@f.test');
    $theirConnection = chanConnection($theirs);

    // The whole-tenant feed...
    authorize($owner, "private-tenant-channel.{$theirs->id}")
        ->assertForbidden();

    // ...and a specific connection on it. Owner of one tenant is nobody in another.
    authorize($owner, "private-tenant.{$theirs->id}.connection.{$theirConnection->id}")
        ->assertForbidden();

    expect($mine->id)->not->toBe($theirs->id);
});

test('a connection id from another tenant cannot be smuggled through an owner subscription', function () {
    [$mine, $owner] = chanTenant('owner@g.test');
    [$theirs] = chanTenant('owner@h.test');
    $theirConnection = chanConnection($theirs);

    // Own tenant id in the channel name, someone else's connection id inside it.
    authorize($owner, "private-tenant.{$mine->id}.connection.{$theirConnection->id}")
        ->assertForbidden();
});

test('an agent may subscribe to their own user channel only', function () {
    [$tenant] = chanTenant('owner@i.test');
    $agent = chanAgent($tenant);
    $other = chanAgent($tenant);

    authorize($agent, 'private-App.Models.User.'.$agent->id)->assertOk();
    authorize($agent, 'private-App.Models.User.'.$other->id)->assertForbidden();
});

test('conversation events ride the connection channel, and tenant events the tenant channel', function () {
    [$tenant] = chanTenant('owner@j.test');
    $connection = chanConnection($tenant);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    $expected = "private-tenant.{$tenant->id}.connection.{$connection->id}";

    expect((new MessageReceived($message))->broadcastOn()[0]->name)->toBe($expected)
        ->and((new ConversationUpdated($conversation))->broadcastOn()[0]->name)->toBe($expected)
        ->and((new ConnectionUpdated($connection))->broadcastOn()[0]->name)->toBe($expected);
});
