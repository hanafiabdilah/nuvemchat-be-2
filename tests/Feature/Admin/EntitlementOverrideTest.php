<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function entitlementAdmin(): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.entitlements.manage', 'web'));

    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole($role);

    return $user;
}

/** A tenant on a plan that has chat but not the funnel. */
function tenantOnStarterPlan(): Tenant
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->update(['tenant_id' => $tenant->id]);

    $plan = Plan::create([
        'name' => 'Starter',
        'slug' => 'starter-'.uniqid(),
        'price_cents' => 4900,
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true, 'crm' => false],
        'quotas' => ['max_connections' => 1],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'features_snapshot' => $plan->features,
        'quotas_snapshot' => $plan->quotas,
        'current_period_start' => now()->subDays(3),
        'current_period_end' => now()->addDays(27),
    ]);

    $tenant->update(['current_subscription_id' => $subscription->id]);

    return $tenant->fresh();
}

test('an override turns a feature on without touching the plan', function () {
    $tenant = tenantOnStarterPlan();
    $gate = app(SubscriptionGate::class);

    expect($gate->feature($tenant, 'crm'))->toBeFalse();

    $this->actingAs(entitlementAdmin(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}/entitlements", [
            'features' => ['crm' => true],
            'expires_at' => now()->addMonth()->toIso8601String(),
            'note' => 'Evaluating the funnel',
        ])
        ->assertOk()
        ->assertJsonPath('data.override_active', true);

    // The plan itself is untouched — the exception is on the account.
    expect($tenant->currentSubscription->plan->features['crm'])->toBeFalse();
    expect($gate->feature($tenant->fresh(), 'crm'))->toBeTrue();
});

test('the grant takes effect immediately rather than at the next cache boundary', function () {
    $tenant = tenantOnStarterPlan();
    $gate = app(SubscriptionGate::class);

    // Warm the entitlement cache the enforcement path reads.
    $gate->feature($tenant, 'crm');

    $this->actingAs(entitlementAdmin(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}/entitlements", ['features' => ['crm' => true]])
        ->assertOk();

    expect($gate->feature($tenant->fresh(), 'crm'))->toBeTrue();
});

test('an expired override stops applying but stays on the record', function () {
    $tenant = tenantOnStarterPlan();

    $tenant->update(['entitlement_overrides' => [
        'features' => ['crm' => true],
        'quotas' => [],
        'expires_at' => now()->subDay()->toIso8601String(),
        'note' => 'Trial that ended',
    ]]);

    expect(app(SubscriptionGate::class)->feature($tenant->fresh(), 'crm'))->toBeFalse();

    $res = $this->actingAs(entitlementAdmin(), 'sanctum')
        ->getJson("/api/admin/customers/{$tenant->id}/entitlements")
        ->assertOk();

    // "No exception" and "the exception ran out" are different answers.
    expect($res->json('data.override_active'))->toBeFalse();
    expect($res->json('data.override.note'))->toBe('Trial that ended');
});

test('an override raises a quota', function () {
    $tenant = tenantOnStarterPlan();

    $this->actingAs(entitlementAdmin(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}/entitlements", [
            'quotas' => ['max_connections' => 10],
        ])
        ->assertOk();

    expect(app(SubscriptionGate::class)->quota($tenant->fresh(), 'max_connections'))->toBe(10);
});

test('an override never grants platform access on its own', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);

    $this->actingAs(entitlementAdmin(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}/entitlements", ['features' => ['chat' => true]])
        ->assertOk();

    // Whether the customer is paying is a different question from what their
    // tier includes; comping an account is still its own action.
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('an empty override is refused rather than stored as a no-op', function () {
    $tenant = tenantOnStarterPlan();

    $this->actingAs(entitlementAdmin(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}/entitlements", ['features' => [], 'quotas' => []])
        ->assertStatus(422);
});

test('revoking an override restores the plan', function () {
    $tenant = tenantOnStarterPlan();
    $admin = entitlementAdmin();

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}/entitlements", ['features' => ['crm' => true]])
        ->assertOk();

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/admin/customers/{$tenant->id}/entitlements")
        ->assertOk()
        ->assertJsonPath('data.override', null);

    expect(app(SubscriptionGate::class)->feature($tenant->fresh(), 'crm'))->toBeFalse();
});
