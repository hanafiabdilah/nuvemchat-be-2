<?php

use App\Models\Admin;
use App\Models\SystemHeartbeat;
use App\Support\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function healthAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.health.view', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function healthCheck(array $payload, string $key): ?array
{
    return collect($payload['data']['checks'])->firstWhere('key', $key);
}

test('a process that has never pinged reads unknown, not down', function () {
    // On a fresh deploy nothing has checked in yet. Reporting an outage on
    // every install's first minute is how a health page gets ignored.
    $res = $this->actingAs(healthAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();

    expect(healthCheck($res->json(), 'process:discord:gateway')['status'])->toBe('unknown');
    expect($res->json('data.status'))->toBe('unknown');
});

test('a recent heartbeat reads ok', function () {
    Heartbeat::ping('discord:gateway', ['sessions' => 2]);

    $res = $this->actingAs(healthAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();
    $check = healthCheck($res->json(), 'process:discord:gateway');

    expect($check['status'])->toBe('ok');
    expect($check['meta']['extra']['sessions'])->toBe(2);
});

test('one missed interval is late, three is down', function () {
    $expected = Heartbeat::expectedInterval('discord:gateway');

    expect(Heartbeat::verdict(now()->subSeconds($expected - 5), $expected))->toBe('ok');
    expect(Heartbeat::verdict(now()->subSeconds($expected * 2), $expected))->toBe('late');
    expect(Heartbeat::verdict(now()->subSeconds($expected * 5), $expected))->toBe('down');
});

test('the page status is the worst check present', function () {
    // Everything healthy except one dead daemon: the header must not say ok.
    foreach (array_keys(Heartbeat::PROCESSES) as $name) {
        Heartbeat::ping($name);
    }

    SystemHeartbeat::where('name', 'discord:gateway')->update([
        'beat_at' => now()->subHours(2),
    ]);

    $res = $this->actingAs(healthAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();

    expect($res->json('data.status'))->toBe('down');
});

test('a heartbeat is rewritten in place rather than appended', function () {
    Heartbeat::ping('media:purge');
    Heartbeat::ping('media:purge');

    // The only question anyone asks is "when did it last run", so history is noise.
    expect(SystemHeartbeat::where('name', 'media:purge')->count())->toBe(1);
});

test('a throttled ping does not write on every call', function () {
    Heartbeat::throttledPing('queue:default', 60);
    $first = SystemHeartbeat::firstWhere('name', 'queue:default')->beat_at;

    $this->travel(5)->seconds();
    Heartbeat::throttledPing('queue:default', 60);

    expect(SystemHeartbeat::firstWhere('name', 'queue:default')->beat_at->timestamp)
        ->toBe($first->timestamp);
});

test('a failing heartbeat write never breaks the process it monitors', function () {
    // The table is gone; the ping must degrade to "looks dead", not throw.
    Schema::drop('system_heartbeats');

    Heartbeat::ping('media:purge');
})->throwsNoExceptions();

test('an admin without the permission cannot read platform health', function () {
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/health')->assertForbidden();
});
