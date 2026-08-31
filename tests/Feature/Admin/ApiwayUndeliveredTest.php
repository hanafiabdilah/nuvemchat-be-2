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
 * `meta.needs_refund` has been written since API Way shipped and read by
 * nothing: a payment captured for an instance that was never provisioned had
 * no screen anywhere, only a line in the log. These cover the two readers that
 * close that loop — the Health page, and the per-customer panel that settles
 * the flag once the money is actually returned.
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

test('a captured payment with no instance turns the health check red', function () {
    $tenant = apiwayOpsTenant();
    apiwayOpsRow($tenant, ApiwaySubscriptionStatus::Failed, [
        'needs_refund' => true,
        'failure' => ['code' => 'no_enabled_subnet_capacity', 'message' => 'Sem capacidade.', 'at' => now()->toISOString()],
    ]);

    $res = $this->actingAs(apiwayOpsAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();
    $check = collect($res->json('data.checks'))->firstWhere('key', 'apiway:undelivered');

    expect($check['status'])->toBe('down')
        ->and($check['meta']['awaiting_refund'])->toBe(1)
        // Named, not just counted — an operator cannot act on a number.
        ->and($check['meta']['rows'][0]['tenant_id'])->toBe($tenant->id)
        ->and($check['meta']['rows'][0]['reason'])->toBe('no_enabled_subnet_capacity');
});

test('a purchase merely held at capacity warns rather than alarms', function () {
    apiwayOpsRow(apiwayOpsTenant(), ApiwaySubscriptionStatus::Provisioning, [
        'capacity_hold' => ['code' => 'platform_capacity_reached', 'since' => now()->toISOString(), 'attempts' => 2],
    ]);

    $res = $this->actingAs(apiwayOpsAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();
    $check = collect($res->json('data.checks'))->firstWhere('key', 'apiway:undelivered');

    expect($check['status'])->toBe('warn')
        ->and($check['meta']['held_at_capacity'])->toBe(1)
        ->and($check['meta']['awaiting_refund'])->toBe(0);
});

test('an ordinary healthy platform reads ok', function () {
    apiwayOpsRow(apiwayOpsTenant(), ApiwaySubscriptionStatus::Active);

    $res = $this->actingAs(apiwayOpsAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();

    expect(collect($res->json('data.checks'))->firstWhere('key', 'apiway:undelivered')['status'])->toBe('ok');
});

test('the subscription list exposes the refund flag and can filter to it', function () {
    $tenant = apiwayOpsTenant();
    $owed = apiwayOpsRow($tenant, ApiwaySubscriptionStatus::Failed, ['needs_refund' => true]);
    apiwayOpsRow($tenant, ApiwaySubscriptionStatus::Active);

    $admin = apiwayOpsAdmin();

    $all = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?tenant_id='.$tenant->id)
        ->assertOk();

    expect($all->json('data'))->toHaveCount(2);

    // The JSON predicate behind ?attention has to survive both DB drivers.
    $flagged = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?attention=1')
        ->assertOk();

    expect($flagged->json('data'))->toHaveCount(1)
        ->and($flagged->json('data.0.id'))->toBe($owed->id)
        ->and($flagged->json('data.0.needs_refund'))->toBeTrue()
        ->and($flagged->json('data.0.needs_attention'))->toBeTrue();
});

test('settling a refund clears it from the attention list and is audited', function () {
    $tenant = apiwayOpsTenant();
    $row = apiwayOpsRow($tenant, ApiwaySubscriptionStatus::Failed, ['needs_refund' => true]);

    $this->actingAs(apiwayOpsAdmin(), 'sanctum')
        ->postJson("/api/admin/apiway/subscriptions/{$row->id}/settle-refund")
        ->assertOk()
        ->assertJsonPath('data.needs_attention', false);

    expect($row->fresh()->meta['refund_settled_at'])->not->toBeNull()
        ->and(AuditLog::where('action', 'apiway.refund.settled')->count())->toBe(1);

    $left = $this->actingAs(apiwayOpsAdmin(), 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?attention=1')
        ->assertOk();

    expect($left->json('data'))->toHaveCount(0);
});

test('a subscription that was never flagged cannot be marked refunded', function () {
    $row = apiwayOpsRow(apiwayOpsTenant(), ApiwaySubscriptionStatus::Active);

    $this->actingAs(apiwayOpsAdmin(), 'sanctum')
        ->postJson("/api/admin/apiway/subscriptions/{$row->id}/settle-refund")
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_flagged');
});
