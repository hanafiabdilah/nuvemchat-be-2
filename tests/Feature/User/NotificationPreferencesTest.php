<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function notifyPrefsUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function notifyPrefsConnection(User $user, string $name = 'WhatsApp'): Connection
{
    return Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => $name,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
}

it('notifies about everything for an account that never opened the page', function () {
    $user = notifyPrefsUser();

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.notification_preferences.incoming_messages', true)
        ->assertJsonPath('data.notification_preferences.muted_connection_ids', []);
});

it('turns every incoming-message notification off', function () {
    $user = notifyPrefsUser();

    $this->actingAs($user)
        ->putJson('/api/user/notification-preferences', ['incoming_messages' => false])
        ->assertOk()
        ->assertJsonPath('notification_preferences.incoming_messages', false);

    expect($user->fresh()->notificationSettings()['incoming_messages'])->toBeFalse();
});

it('silences a single connection', function () {
    $user = notifyPrefsUser();
    $connection = notifyPrefsConnection($user);

    $this->actingAs($user)
        ->putJson('/api/user/notification-preferences', ['muted_connection_ids' => [$connection->id]])
        ->assertOk()
        ->assertJsonPath('notification_preferences.muted_connection_ids', [$connection->id])
        // The master switch is untouched by a per-connection change.
        ->assertJsonPath('notification_preferences.incoming_messages', true);
});

it('merges a partial update instead of dropping the other switch', function () {
    $user = notifyPrefsUser();
    $connection = notifyPrefsConnection($user);
    $user->forceFill(['notification_preferences' => [
        'incoming_messages' => true,
        'muted_connection_ids' => [$connection->id],
    ]])->save();

    $this->actingAs($user)
        ->putJson('/api/user/notification-preferences', ['incoming_messages' => false])
        ->assertOk();

    expect($user->fresh()->notificationSettings())
        ->toBe(['incoming_messages' => false, 'muted_connection_ids' => [$connection->id]]);
});

it('drops connection ids that do not belong to the tenant', function () {
    $user = notifyPrefsUser();
    $own = notifyPrefsConnection($user);

    $stranger = notifyPrefsUser();
    $theirs = notifyPrefsConnection($stranger, 'Someone else');

    $this->actingAs($user)
        ->putJson('/api/user/notification-preferences', [
            'muted_connection_ids' => [$own->id, $theirs->id, 999999],
        ])
        ->assertOk()
        ->assertJsonPath('notification_preferences.muted_connection_ids', [$own->id]);
});

it('rejects a list that is not made of ids', function () {
    $user = notifyPrefsUser();

    $this->actingAs($user)
        ->putJson('/api/user/notification-preferences', ['muted_connection_ids' => ['all']])
        ->assertStatus(422);

    expect($user->fresh()->notification_preferences)->toBeNull();
});
