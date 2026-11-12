<?php

use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Admin;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * The customer list filters and sorts on the server, before paging. It used to
 * narrow only the rows on screen, so a page could say "no customers" while the
 * next page held matches.
 */

function customerListAdmin(): Admin
{
    $role = Role::findOrCreate('customer-list-reader', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.customers.view', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function customerListPlan(string $name): Plan
{
    return Plan::create([
        'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'price_cents' => 9900,
        'billing_cycle' => 'monthly', 'features' => ['chat' => true], 'quotas' => [],
    ]);
}

/**
 * @param  array{name?: string, email?: string, verified?: bool, market?: string, created?: string, plan?: Plan, status?: SubscriptionStatus, ends?: string}  $o
 */
function customerListTenant(array $o = []): Tenant
{
    $owner = User::factory()->create([
        'name' => $o['name'] ?? fake()->name(),
        'email' => $o['email'] ?? fake()->unique()->safeEmail(),
        'whatsapp_number' => '5511'.fake()->unique()->numerify('#########'),
        'whatsapp_verified_at' => ($o['verified'] ?? true) ? now() : null,
    ]);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->update(['tenant_id' => $tenant->id]);

    if (isset($o['created'])) {
        $tenant->forceFill(['created_at' => $o['created']])->saveQuietly();
    }

    if (isset($o['market'])) {
        // market_code is fixed once a tenant exists; tests set it straight in the table.
        Tenant::query()->whereKey($tenant->id)->update(['market_code' => $o['market']]);
    }

    if (isset($o['plan'])) {
        $subscription = Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $o['plan']->id,
            'status' => $o['status'] ?? SubscriptionStatus::Active,
            'payment_method' => PaymentMethod::Pix, 'price_cents' => 9900, 'currency' => 'BRL',
            'billing_cycle' => 'monthly', 'current_period_end' => $o['ends'] ?? now()->addDays(20),
        ]);
        $tenant->update(['current_subscription_id' => $subscription->id]);
    }

    return $tenant->fresh();
}

function customerIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

test('status filters on the current subscription, and "none" finds customers without a plan', function () {
    $pro = customerListPlan('Pro');
    $paying = customerListTenant(['plan' => $pro, 'status' => SubscriptionStatus::Active]);
    $late = customerListTenant(['plan' => $pro, 'status' => SubscriptionStatus::PastDue]);
    $none = customerListTenant();

    $admin = customerListAdmin();

    $ids = customerIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/customers?status=past_due')->assertOk());
    expect($ids)->toBe([$late->id]);

    $ids = customerIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/customers?status=none,past_due')->assertOk());
    expect($ids)->toEqualCanonicalizing([$late->id, $none->id])
        ->not->toContain($paying->id);
});

test('plan, verified and market filters combine', function () {
    Market::create([
        'code' => 'ID', 'name' => 'Indonesia', 'currency' => 'IDR', 'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta', 'phone_country' => '62', 'status' => 'draft',
    ]);
    $pro = customerListPlan('Pro');
    $start = customerListPlan('Start');
    $match = customerListTenant(['plan' => $pro, 'verified' => false, 'market' => 'ID']);
    customerListTenant(['plan' => $pro, 'verified' => true, 'market' => 'ID']);
    customerListTenant(['plan' => $start, 'verified' => false, 'market' => 'ID']);
    customerListTenant(['plan' => $pro, 'verified' => false, 'market' => 'BR']);

    $response = $this->actingAs(customerListAdmin(), 'sanctum')
        ->getJson("/api/admin/customers?plan_id={$pro->id}&verified=no&market=ID")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    expect(customerIds($response))->toBe([$match->id]);
});

test('sign-up and plan-end ranges are inclusive calendar days', function () {
    $pro = customerListPlan('Pro');
    $inRange = customerListTenant(['created' => '2026-03-10 23:30:00', 'plan' => $pro, 'ends' => '2026-04-05 10:00:00']);
    customerListTenant(['created' => '2026-03-11 00:10:00', 'plan' => $pro, 'ends' => '2026-04-05 10:00:00']);
    customerListTenant(['created' => '2026-03-01 12:00:00', 'plan' => $pro, 'ends' => '2026-04-20 10:00:00']);

    $response = $this->actingAs(customerListAdmin(), 'sanctum')
        ->getJson('/api/admin/customers?created_from=2026-03-01&created_to=2026-03-10&period_end_from=2026-04-01&period_end_to=2026-04-05')
        ->assertOk();

    expect(customerIds($response))->toBe([$inRange->id]);
});

test('sorts by owner name and by plan end, with customers without a plan last', function () {
    $pro = customerListPlan('Pro');
    $b = customerListTenant(['name' => 'Bruno', 'plan' => $pro, 'ends' => now()->addDays(30)]);
    $a = customerListTenant(['name' => 'Ana', 'plan' => $pro, 'ends' => now()->addDays(2)]);
    $c = customerListTenant(['name' => 'Carla']);

    $admin = customerListAdmin();

    expect(customerIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/customers?sort=name&dir=asc')))
        ->toBe([$a->id, $b->id, $c->id]);

    expect(customerIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/customers?sort=period_end&dir=asc')))
        ->toBe([$a->id, $b->id, $c->id]);

    // The old two values keep working.
    expect(customerIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/customers?sort=oldest')))
        ->toBe([$b->id, $a->id, $c->id]);
});

test('an unknown status is refused instead of matching nothing', function () {
    $this->actingAs(customerListAdmin(), 'sanctum')
        ->getJson('/api/admin/customers?status=paid')
        ->assertUnprocessable();
});

test('the summary lists the filter choices and the week\'s sign-ups', function () {
    $pro = customerListPlan('Pro');
    customerListTenant(['plan' => $pro]);
    customerListTenant(['created' => now()->subDays(20)->toDateTimeString()]);

    $this->actingAs(customerListAdmin(), 'sanctum')
        ->getJson('/api/admin/customers/summary')
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.new_7d', 1)
        ->assertJsonPath('data.filters.plans.0.name', 'Pro')
        ->assertJsonPath('data.filters.statuses.0', 'trialing');
});
