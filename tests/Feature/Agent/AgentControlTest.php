<?php

use App\Events\UserPresenceUpdated;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The agent control page: a supervisor can turn a colleague's switch to
 * Offline, and only that way round — going Online stays the agent's own act.
 */
uses(RefreshDatabase::class);

function acOwner(): User
{
    $owner = User::factory()->create(['name' => 'Olivia Owner']);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    // In a real workspace the owner role holds every permission (seeder).
    $role = Role::findOrCreate('owner', 'web');
    $role->givePermissionTo('agents.set-offline');
    $owner->assignRole($role);

    return $owner->fresh();
}

function acAgent(int $tenantId, string $name = 'Ana Agent', bool $atDesk = true): User
{
    $agent = User::factory()->create(['tenant_id' => $tenantId, 'name' => $name]);

    if ($atDesk) {
        $agent->markSeen();
    }

    return $agent->fresh();
}

/** A supervisor holding exactly the agent control permission. */
function acSupervisor(int $tenantId, string $name = 'Sam Supervisor'): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId, 'name' => $name]);
    $role = Role::findOrCreate('supervisor-'.$user->id, 'web');
    $role->givePermissionTo('agents.set-offline');
    $user->assignRole($role);

    return $user->fresh();
}

beforeEach(function () {
    Permission::findOrCreate('agents.set-offline', 'web');
});

test('the roster tells online, away and offline apart', function () {
    $owner = acOwner();
    $supervisor = acSupervisor($owner->tenant_id);
    $online = acAgent($owner->tenant_id, 'Bruno Online');
    $away = acAgent($owner->tenant_id, 'Carla Away', atDesk: false);
    $off = acAgent($owner->tenant_id, 'Davi Off');
    $off->setPresence(false);
    $supervisor->markSeen();

    // Somebody in another workspace is never listed.
    acAgent(acOwner()->tenant_id, 'Elsewhere');

    $rows = collect($this->actingAs($supervisor)->getJson('/api/agent-control')
        ->assertOk()
        ->json('data'))->keyBy('name');

    expect($rows->keys()->all())->not->toContain('Elsewhere')
        ->and($rows['Bruno Online']['status'])->toBe('online')
        ->and($rows['Bruno Online']['can_set_offline'])->toBeTrue()
        ->and($rows['Carla Away']['status'])->toBe('away')
        ->and($rows['Carla Away']['can_set_offline'])->toBeTrue()
        ->and($rows['Davi Off']['status'])->toBe('offline')
        ->and($rows['Davi Off']['can_set_offline'])->toBeFalse()
        ->and($rows['Davi Off']['offline_set_by'])->toBeNull()
        // The owner is listed, but is not somebody a role switches off.
        ->and($rows['Olivia Owner']['can_set_offline'])->toBeFalse();
});

test('a supervisor turns an online agent offline, and the agent is told who', function () {
    Event::fake([UserPresenceUpdated::class]);

    $owner = acOwner();
    $supervisor = acSupervisor($owner->tenant_id);
    $agent = acAgent($owner->tenant_id);

    $this->actingAs($supervisor)
        ->postJson("/api/agent-control/{$agent->id}/offline")
        ->assertOk()
        ->assertJsonPath('changed', true)
        ->assertJsonPath('data.status', 'offline')
        ->assertJsonPath('data.offline_set_by.name', 'Sam Supervisor')
        ->assertJsonPath('data.can_set_offline', false);

    $agent->refresh();

    expect($agent->offline_since)->not->toBeNull()
        ->and($agent->offline_set_by_user_id)->toBe($supervisor->id)
        ->and($agent->isOnline())->toBeFalse();

    Event::assertDispatched(UserPresenceUpdated::class, fn ($event) => $event->userId === $agent->id
        && $event->presence === 'offline'
        && $event->broadcastWith() === ['presence' => 'offline', 'changed_by' => 'Sam Supervisor']);

    expect(AuditLog::where('action', 'agent.presence_set_offline')->count())->toBe(1);
});

test('a second press on an agent already offline changes and announces nothing', function () {
    $owner = acOwner();
    $supervisor = acSupervisor($owner->tenant_id);
    $agent = acAgent($owner->tenant_id);
    $agent->setPresence(false);

    Event::fake([UserPresenceUpdated::class]);

    $this->actingAs($supervisor)
        ->postJson("/api/agent-control/{$agent->id}/offline")
        ->assertOk()
        ->assertJsonPath('changed', false)
        // Still the agent's own Offline: a late press does not take it over.
        ->assertJsonPath('data.offline_set_by', null);

    Event::assertNotDispatched(UserPresenceUpdated::class);
    expect(AuditLog::where('action', 'agent.presence_set_offline')->count())->toBe(0);
});

test('there is no way back to online from the page', function () {
    $owner = acOwner();
    $supervisor = acSupervisor($owner->tenant_id);
    $agent = acAgent($owner->tenant_id);
    $agent->setPresence(false, by: $supervisor);

    $this->actingAs($supervisor)->postJson("/api/agent-control/{$agent->id}/online")->assertNotFound();
    $this->actingAs($supervisor)->putJson('/api/user/presence', ['status' => 'online'])->assertOk();

    // The supervisor's own switch moved, never the agent's.
    expect($agent->fresh()->offline_since)->not->toBeNull();
});

test('the agent turning themselves back on forgets who switched them off', function () {
    $owner = acOwner();
    $supervisor = acSupervisor($owner->tenant_id);
    $agent = acAgent($owner->tenant_id);

    $this->actingAs($supervisor)->postJson("/api/agent-control/{$agent->id}/offline")->assertOk();

    $this->actingAs($agent->fresh())
        ->putJson('/api/user/presence', ['status' => 'online'])
        ->assertOk()
        ->assertJsonPath('presence', 'online');

    expect($agent->fresh()->offline_since)->toBeNull()
        ->and($agent->fresh()->offline_set_by_user_id)->toBeNull();
});

test('the owner cannot be switched off by anybody but the owner', function () {
    $owner = acOwner();
    $owner->markSeen();
    $supervisor = acSupervisor($owner->tenant_id);

    $this->actingAs($supervisor)
        ->postJson("/api/agent-control/{$owner->id}/offline")
        ->assertForbidden()
        ->assertJsonPath('code', 'agent_protected');

    expect($owner->fresh()->offline_since)->toBeNull();

    // The owner may use the page on themselves, which records no "by".
    $this->actingAs($owner)
        ->postJson("/api/agent-control/{$owner->id}/offline")
        ->assertOk()
        ->assertJsonPath('changed', true)
        ->assertJsonPath('data.offline_set_by', null);
});

test('an agent of another workspace is out of reach', function () {
    $owner = acOwner();
    $supervisor = acSupervisor($owner->tenant_id);
    $stranger = acAgent(acOwner()->tenant_id);

    $this->actingAs($supervisor)
        ->postJson("/api/agent-control/{$stranger->id}/offline")
        ->assertNotFound();

    expect($stranger->fresh()->offline_since)->toBeNull();
});

test('without the permission the page and the action are closed', function () {
    $owner = acOwner();
    $plain = acAgent($owner->tenant_id, 'Plain Agent');
    $agent = acAgent($owner->tenant_id);

    $this->actingAs($plain)->getJson('/api/agent-control')->assertForbidden();
    $this->actingAs($plain)->postJson("/api/agent-control/{$agent->id}/offline")->assertForbidden();

    expect($agent->fresh()->offline_since)->toBeNull();
});

test('signing in still turns the switch back on and clears who turned it off', function () {
    $owner = acOwner();
    $supervisor = acSupervisor($owner->tenant_id);
    $agent = acAgent($owner->tenant_id);
    $agent->setPresence(false, by: $supervisor);

    $agent->setPresence(true);

    expect($agent->fresh()->offline_set_by_user_id)->toBeNull();
});
