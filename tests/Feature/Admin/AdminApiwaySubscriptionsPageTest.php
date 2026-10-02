<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Models\Admin;
use App\Models\ApiwaySubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Back Office API Way page: one list across every workspace, plus the
 * platform's exposure on top of it.
 */
uses(RefreshDatabase::class);

function apwPageAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.subscriptions.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function apwPageTenant(string $name): Tenant
{
    $user = User::factory()->create(['name' => $name, 'email' => 'apw-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function apwPageRow(Tenant $tenant, array $attributes = []): ApiwaySubscription
{
    return ApiwaySubscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'external_ref' => 'pingly-apw-page-'.uniqid(),
        'source' => ApiwaySubscriptionSource::Unit,
        'cycle' => 'mensal',
        'quantity' => 1,
        'unit_price_cents' => 4990,
        'total_price_cents' => 4990,
        'currency' => 'BRL',
        'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::Active,
        'expires_at' => now()->addDays(20),
    ], $attributes));
}

test('the summary prices paid units per month and counts plan units apart', function () {
    $tenant = apwPageTenant('Loja Aurora');
    apwPageRow($tenant);
    apwPageRow($tenant, ['cycle' => 'anual', 'total_price_cents' => 48000, 'quantity' => 2, 'expires_at' => now()->addDays(3)]);
    apwPageRow($tenant, ['source' => ApiwaySubscriptionSource::PlanIncluded, 'total_price_cents' => 0, 'quantity' => 3]);
    apwPageRow($tenant, ['status' => ApiwaySubscriptionStatus::Cancelled]);

    $data = $this->actingAs(apwPageAdmin(), 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions/summary')
        ->assertOk()
        ->json('data');

    expect($data['live_count'])->toBe(3)
        ->and($data['live_units'])->toBe(6)
        ->and($data['included_units'])->toBe(3)
        ->and($data['expiring_count'])->toBe(1)
        ->and($data['tenant_count'])->toBe(1)
        // 4990 monthly + 48000 / 12 yearly.
        ->and($data['monthly_revenue_cents'])->toBe(4990 + 4000);
});

test('the list searches by customer and narrows to what lapses this week', function () {
    $aurora = apwPageTenant('Loja Aurora');
    $other = apwPageTenant('Outra Empresa');
    $soon = apwPageRow($aurora, ['expires_at' => now()->addDays(2)]);
    apwPageRow($aurora);
    apwPageRow($other);

    $admin = apwPageAdmin();

    $rows = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?search=Aurora')
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['tenant_name'])->toBe('Loja Aurora');

    $rows = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?expiring=1')
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('id')->all())->toBe([$soon->id]);
});
