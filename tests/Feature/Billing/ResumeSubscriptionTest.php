<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
});

function resumeTestGrant(User $user): void
{
    $role = Role::findOrCreate('owner', 'web');
    foreach (['billing.view', 'billing.manage'] as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $user->assignRole($role);
}

/**
 * A subscription scheduled to end — cancel_at_period_end true, still inside
 * its current period. Built by hand rather than through
 * BillingService::subscribe() so this test does not need a payment-service
 * fake: resume() only ever touches the two columns cancel() set.
 */
function resumableSubscription(bool $cancelled = true, ?\Carbon\Carbon $periodEnd = null): array
{
    $user = User::factory()->create(['email' => 'resume-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Bia Nogueira',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    resumeTestGrant($user);

    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-'.uniqid(),
        'price_cents' => 9990,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => $plan->price_cents,
        'current_period_start' => now()->subDays(10),
        'current_period_end' => $periodEnd ?? now()->addDays(20),
        'cancel_at_period_end' => $cancelled,
        'cancelled_at' => $cancelled ? now() : null,
    ]);

    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return [$tenant->fresh(), $subscription->fresh(), $user];
}

test('resuming a scheduled cancellation clears the flag and stays on the same subscription', function () {
    [$tenant, $subscription, $user] = resumableSubscription();

    Sanctum::actingAs($user);
    $response = $this->postJson('/api/billing/resume');

    $response->assertOk();
    $response->assertJsonPath('data.id', $subscription->id);
    $response->assertJsonPath('data.cancel_at_period_end', false);

    $fresh = $subscription->fresh();
    expect($fresh->cancel_at_period_end)->toBeFalse();
    expect($fresh->cancelled_at)->toBeNull();
    // Nothing else about the subscription moved — resume is not a re-subscribe.
    expect($fresh->status)->toBe(SubscriptionStatus::Active);
    expect($tenant->fresh()->current_subscription_id)->toBe($subscription->id);
});

test('resuming a subscription that was never cancelled is refused', function () {
    [, , $user] = resumableSubscription(cancelled: false);

    Sanctum::actingAs($user);
    $response = $this->postJson('/api/billing/resume');

    $response->assertStatus(422);
});

test('resuming after the period already ended is refused', function () {
    [, $subscription, $user] = resumableSubscription(periodEnd: now()->subDay());

    Sanctum::actingAs($user);
    $response = $this->postJson('/api/billing/resume');

    $response->assertStatus(422);
    // Refused, not silently applied — the flag stays exactly as it was.
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();
});

test('resuming with no subscription at all is a 404', function () {
    $user = User::factory()->create(['email' => 'resume-none-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    resumeTestGrant($user);

    Sanctum::actingAs($user);
    $response = $this->postJson('/api/billing/resume');

    $response->assertStatus(404);
});
