<?php

use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Numbers\VirtualNumberStatus;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

/**
 * The first-run guide: who still has to pass it, and the ways out of it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => true]);
});

function onboardingOwner(): User
{
    foreach (['billing.view', 'billing.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create([
        'email' => 'onb-'.uniqid().'@example.test',
        'whatsapp_verified_at' => now(),
    ]);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->givePermissionTo(['billing.view', 'billing.manage']);

    return $user->fresh();
}

function onboardingFlag(User $user): array
{
    Sanctum::actingAs($user);

    return test()->getJson('/api/user')->assertOk()->json('data.onboarding');
}

it('is required for a workspace that has not paid for or bought anything', function () {
    expect(onboardingFlag(onboardingOwner()))->toBe(['required' => true, 'completed_by' => null]);
});

it('stops being required once the owner skips it', function () {
    $user = onboardingOwner();
    Sanctum::actingAs($user);

    $this->postJson('/api/onboarding/skip')
        ->assertOk()
        ->assertJsonPath('data.required', false)
        ->assertJsonPath('data.completed_by', 'skipped');

    expect($user->tenant->fresh()->onboarding_skipped_at)->not->toBeNull()
        ->and(onboardingFlag($user)['required'])->toBeFalse();
});

it('can be skipped by a workspace with no plan while billing is enforced', function () {
    // The route has to be outside the suspended-subscription gate, or the
    // only workspaces the guide is for could never leave it.
    Sanctum::actingAs(onboardingOwner());

    $this->postJson('/api/onboarding/skip')->assertOk();
});

it('refuses the skip without billing.manage', function () {
    $user = onboardingOwner();
    $user->revokePermissionTo('billing.manage');
    Sanctum::actingAs($user->fresh());

    $this->postJson('/api/onboarding/skip')->assertForbidden();
});

it('is done once the workspace has paid for a plan, even if it lapsed since', function () {
    $user = onboardingOwner();

    Invoice::create([
        'tenant_id' => $user->tenant_id, 'purpose' => InvoicePurpose::Subscription,
        'status' => InvoiceStatus::Paid, 'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 14900, 'currency' => 'BRL', 'due_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]);

    expect(onboardingFlag($user))->toBe(['required' => false, 'completed_by' => 'plan']);
});

it('is not done by a plan invoice that was never paid', function () {
    $user = onboardingOwner();

    Invoice::create([
        'tenant_id' => $user->tenant_id, 'purpose' => InvoicePurpose::Subscription,
        'status' => InvoiceStatus::Pending, 'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 14900, 'currency' => 'BRL', 'due_date' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ]);

    expect(onboardingFlag($user)['required'])->toBeTrue();
});

it('is done once the workspace has rented a number, with no plan at all', function () {
    $user = onboardingOwner();

    VirtualNumber::create([
        'tenant_id' => $user->tenant_id, 'app' => 'whatsapp', 'ddd' => '11',
        'status' => VirtualNumberStatus::Active, 'price_cents' => 3290, 'currency' => 'BRL',
        'purchased_at' => now(),
    ]);

    expect(onboardingFlag($user))->toBe(['required' => false, 'completed_by' => 'number']);
});

it('is not done by a number rental that failed', function () {
    $user = onboardingOwner();

    VirtualNumber::create([
        'tenant_id' => $user->tenant_id, 'app' => 'whatsapp', 'ddd' => '11',
        'status' => VirtualNumberStatus::Failed, 'price_cents' => 3290, 'currency' => 'BRL',
    ]);

    expect(onboardingFlag($user)['required'])->toBeTrue();
});

it('lets a workspace with no plan read and top up its balance', function () {
    // A numbers-only workspace has no plan by design, and its balance is what
    // pays for the number — a 403 here made that path impossible.
    Sanctum::actingAs(onboardingOwner());

    $this->getJson('/api/credits')->assertOk();
    $this->getJson('/api/credits')->assertJsonMissing(['code' => 'subscription_suspended']);
});

it('blocks the dashboard API until the guide is done', function () {
    Sanctum::actingAs(onboardingOwner());

    $this->getJson('/api/connections')
        ->assertForbidden()
        ->assertJsonPath('code', 'onboarding_required');
});

it('keeps open what the guide itself needs', function () {
    Sanctum::actingAs(onboardingOwner());

    $this->getJson('/api/user')->assertOk();
    $this->getJson('/api/plans')->assertOk();
    $this->getJson('/api/billing/subscription')->assertOk();
    $this->getJson('/api/credits')->assertOk();
    $this->getJson('/api/numbers')->assertJsonMissing(['code' => 'onboarding_required']);
    $this->postJson('/api/user/heartbeat')->assertJsonMissing(['code' => 'onboarding_required']);
});

it('hands over to the subscription gate once skipped', function () {
    // Skipping the guide is not buying anything: a workspace with no plan is
    // still suspended, just no longer told to finish onboarding first.
    $user = onboardingOwner();
    Sanctum::actingAs($user);

    $this->postJson('/api/onboarding/skip')->assertOk();

    $this->getJson('/api/connections')
        ->assertForbidden()
        ->assertJsonPath('code', 'subscription_suspended');
});

it('lets a numbers-only workspace past the guide gate', function () {
    $user = onboardingOwner();
    VirtualNumber::create([
        'tenant_id' => $user->tenant_id, 'app' => 'whatsapp', 'ddd' => '11',
        'status' => VirtualNumberStatus::Active, 'price_cents' => 3290, 'currency' => 'BRL',
        'purchased_at' => now(),
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/connections')->assertJsonMissing(['code' => 'onboarding_required']);
});

it('does not gate anything while billing is not enforced', function () {
    config(['services.billing.enforce' => false]);
    Sanctum::actingAs(onboardingOwner());

    $this->getJson('/api/connections')->assertJsonMissing(['code' => 'onboarding_required']);
});
