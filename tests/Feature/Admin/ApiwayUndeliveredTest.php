<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Models\Admin;
use App\Models\ApiwaySubscription;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The "Instances that could not be made" health check. Nothing is charged for
 * an instance any more, so this is about customers who tried to connect a
 * number and did not get one — held at ProxyBR's cap, or refused.
 */
uses(RefreshDatabase::class);

function apiwayOpsAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.health.view', 'web'));
    $role->givePermissionTo(Permission::findOrCreate('bo.subscriptions.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function apiwayOpsTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'ops-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function apiwayOpsRow(Tenant $tenant, ApiwaySubscriptionStatus $status, ?array $meta = null): ApiwaySubscription
{
    return ApiwaySubscription::create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'pingly-apw-ops-'.uniqid(),
        'source' => ApiwaySubscriptionSource::Unit,
        'cycle' => 'mensal',
        'quantity' => 1,
        'unit_price_cents' => 4990,
        'total_price_cents' => 4990,
        'location_code' => 'br',
        'status' => $status,
        'meta' => $meta,
    ]);
}

function apiwayHealthCheck($test, string $key): array
{
    return collect($test->actingAs(apiwayOpsAdmin(), 'sanctum')
        ->getJson('/api/admin/health')
        ->assertOk()
        ->json('data.checks'))
        ->firstWhere('key', $key);
}

test('an instance held at ProxyBR capacity warns', function () {
    apiwayOpsRow(apiwayOpsTenant(), ApiwaySubscriptionStatus::Provisioning, [
        'capacity_hold' => ['code' => 'platform_capacity_reached', 'since' => now()->toISOString(), 'attempts' => 1],
    ]);

    $check = apiwayHealthCheck($this, 'apiway:undelivered');

    expect($check['status'])->toBe('warn')
        ->and($check['meta']['held_at_capacity'])->toBe(1)
        ->and($check['meta']['rows'][0])->not->toHaveKey('amount_cents');
});

test('an instance refused in the last day warns, an older one does not', function () {
    $tenant = apiwayOpsTenant();
    apiwayOpsRow($tenant, ApiwaySubscriptionStatus::Failed, ['failure' => ['code' => 'no_enabled_subnet_capacity']]);

    $check = apiwayHealthCheck($this, 'apiway:undelivered');
    expect($check['status'])->toBe('warn')->and($check['meta']['failed_24h'])->toBe(1);

    ApiwaySubscription::query()->update(['updated_at' => now()->subDays(2)]);

    expect(apiwayHealthCheck($this, 'apiway:undelivered')['status'])->toBe('ok');
});

test('an ordinary healthy platform reads ok', function () {
    apiwayOpsRow(apiwayOpsTenant(), ApiwaySubscriptionStatus::Active);

    expect(apiwayHealthCheck($this, 'apiway:undelivered')['status'])->toBe('ok');
});

test('there is no refund to settle any more', function () {
    $row = apiwayOpsRow(apiwayOpsTenant(), ApiwaySubscriptionStatus::Failed, ['needs_refund' => true]);

    $this->actingAs(apiwayOpsAdmin(), 'sanctum')
        ->postJson("/api/admin/apiway/subscriptions/{$row->id}/settle-refund")
        ->assertNotFound();
});
