<?php

use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * These go through the HTTP surface on purpose. The service was covered by a
 * test that handed it a `User` it built itself, so it kept passing after admins
 * moved into their own table while every real assign 500'd on the type hint.
 * Only a request carrying an actual `Admin` reproduces that.
 */
function assignAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.subscriptions.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function assignTenant(): Tenant
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->update(['tenant_id' => $tenant->id]);

    return $tenant->fresh();
}

function assignPlan(): Plan
{
    return Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-'.uniqid(),
        'price_cents' => 9900,
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true],
        'quotas' => ['max_connections' => 5],
    ]);
}

test('an admin comps a plan onto a tenant', function () {
    $tenant = assignTenant();
    $plan = assignPlan();
    $admin = assignAdmin();

    $res = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/customers/{$tenant->id}/subscription", [
            'plan_id' => $plan->id,
            'ends_at' => now()->addMonth()->toIso8601String(),
            'note' => 'Comp while they evaluate',
        ])
        ->assertCreated();

    $subscription = Subscription::findOrFail($res->json('data.id'));

    expect($subscription->status)->toBe(SubscriptionStatus::Manual);
    expect($subscription->payment_method)->toBe(PaymentMethod::Manual);
    expect($subscription->price_cents)->toBe(0);
    expect($subscription->manual_note)->toBe('Comp while they evaluate');
    // The grantor is the admin id, not a tenant user's — the two id spaces
    // overlap now that they are separate tables.
    expect($subscription->manual_granted_by)->toBe($admin->id);
    expect($tenant->fresh()->current_subscription_id)->toBe($subscription->id);
});

test('a comp with no plan still grants platform access', function () {
    $tenant = assignTenant();

    $this->actingAs(assignAdmin(), 'sanctum')
        ->postJson("/api/admin/customers/{$tenant->id}/subscription", [])
        ->assertCreated()
        ->assertJsonPath('data.status', SubscriptionStatus::Manual->value);

    expect($tenant->fresh()->currentSubscription->isUsable())->toBeTrue();
});

test('assigning replaces whatever the tenant was on', function () {
    $tenant = assignTenant();
    $admin = assignAdmin();

    $first = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/customers/{$tenant->id}/subscription", ['plan_id' => assignPlan()->id])
        ->assertCreated()
        ->json('data.id');

    $second = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/customers/{$tenant->id}/subscription", ['plan_id' => assignPlan()->id])
        ->assertCreated()
        ->json('data.id');

    expect($second)->not->toBe($first);
    expect($tenant->fresh()->current_subscription_id)->toBe($second);
    expect(Subscription::find($first)->status)->not->toBe(SubscriptionStatus::Manual);
});

test('an admin without the permission is refused', function () {
    $tenant = assignTenant();

    $this->actingAs(Admin::factory()->create(), 'sanctum')
        ->postJson("/api/admin/customers/{$tenant->id}/subscription", [])
        ->assertForbidden();
});
