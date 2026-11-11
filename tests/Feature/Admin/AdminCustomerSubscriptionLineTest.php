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
 * The customer list and the detail header lead with the plan and whether it
 * is being paid, so the resource carries the current subscription in one line.
 */
function subscriptionLineAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.customers.view', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function subscriptionLineTenant(): Tenant
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->update(['tenant_id' => $tenant->id]);

    return $tenant->fresh();
}

test('the customer list and detail carry the current plan and its status', function () {
    $tenant = subscriptionLineTenant();
    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-'.uniqid(),
        'price_cents' => 9900,
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true],
        'quotas' => [],
    ]);
    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::PastDue,
        'payment_method' => PaymentMethod::Pix,
        'price_cents' => 9900,
        'currency' => 'BRL',
        'billing_cycle' => 'monthly',
        'current_period_end' => now()->addDays(3),
    ]);
    $tenant->update(['current_subscription_id' => $subscription->id]);

    $admin = subscriptionLineAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/customers')
        ->assertOk()
        ->assertJsonPath('data.0.subscription.status', 'past_due')
        ->assertJsonPath('data.0.subscription.plan_name', 'Pro');

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/admin/customers/{$tenant->id}")
        ->assertOk()
        ->assertJsonPath('data.subscription.status', 'past_due');
});

test('a customer without a subscription says so with null', function () {
    subscriptionLineTenant();

    $this->actingAs(subscriptionLineAdmin(), 'sanctum')
        ->getJson('/api/admin/customers')
        ->assertOk()
        ->assertJsonPath('data.0.subscription', null);
});
