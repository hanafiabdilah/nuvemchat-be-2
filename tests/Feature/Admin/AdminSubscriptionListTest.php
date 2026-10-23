<?php

use App\Enums\Billing\BillingCycle;
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

function subsListAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.subscriptions.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

/** @param array<string, mixed> $owner */
function subsListTenant(array $owner = [], string $market = 'BR', array $billing = []): Tenant
{
    $user = User::factory()->create($owner);

    $tenant = new Tenant(['user_id' => $user->id, ...$billing]);
    $tenant->market_code = $market;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant;
}

function subsListPlan(string $name): Plan
{
    return Plan::create([
        'name' => $name,
        'slug' => str($name)->slug().'-'.uniqid(),
        'price_cents' => 9900,
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true],
        'quotas' => [],
    ]);
}

/** @param array<string, mixed> $attributes */
function subsListRow(Tenant $tenant, Plan $plan, array $attributes = [], bool $current = true): Subscription
{
    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => 9900,
        'currency' => 'BRL',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
        'quotas_snapshot' => [],
        'features_snapshot' => [],
        ...$attributes,
    ]);

    if ($current) {
        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();
    }

    return $subscription;
}

/** @return list<int> */
function subsListIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

it('searches by owner name, e-mail, WhatsApp, billing document and id', function () {
    $plan = subsListPlan('Pro');
    $ana = subsListRow(subsListTenant(['name' => 'Ana Souza', 'email' => 'ana@loja.com', 'whatsapp_number' => '5511987654321']), $plan);
    $bruno = subsListRow(subsListTenant(
        ['name' => 'Bruno Lima', 'email' => 'bruno@oficina.com'],
        billing: ['billing_name' => 'Oficina do Bruno LTDA', 'billing_document_number' => '12345678000199'],
    ), $plan);

    $admin = subsListAdmin();
    $search = fn (string $term) => subsListIds($this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/subscriptions?search='.urlencode($term))
        ->assertOk());

    expect($search('souza'))->toBe([$ana->id])
        ->and($search('oficina.com'))->toBe([$bruno->id])
        // Typed the way a person reads it, stored as bare digits.
        ->and($search('+55 (11) 98765-4321'))->toBe([$ana->id])
        ->and($search('12.345.678/0001-99'))->toBe([$bruno->id])
        ->and($search('Oficina do Bruno'))->toBe([$bruno->id])
        ->and($search((string) $bruno->id))->toContain($bruno->id);
});

it('finds a subscription by the gateway id a webhook log printed', function () {
    $plan = subsListPlan('Pro');
    $row = subsListRow(subsListTenant(), $plan, [
        'payment_method' => PaymentMethod::Card,
        'payment_instrument_id' => 'mp_preapproval:2c938084abc',
    ]);
    subsListRow(subsListTenant(), $plan);

    $this->actingAs(subsListAdmin(), 'sanctum')
        ->getJson('/api/admin/subscriptions?search=2c938084abc')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $row->id);
});

it('filters by several statuses at once, in either query shape', function () {
    $plan = subsListPlan('Pro');
    $active = subsListRow(subsListTenant(), $plan);
    $pastDue = subsListRow(subsListTenant(), $plan, ['status' => SubscriptionStatus::PastDue]);
    $grace = subsListRow(subsListTenant(), $plan, ['status' => SubscriptionStatus::Grace]);

    $admin = subsListAdmin();

    expect(subsListIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/subscriptions?status=past_due,grace')))
        ->toBe([$pastDue->id, $grace->id])
        ->and(subsListIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/subscriptions?status[]=active')))
        ->toBe([$active->id])
        // The old page sent one bare value; it still means the same thing.
        ->and(subsListIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/subscriptions?status=grace')))
        ->toBe([$grace->id]);
});

it('rejects a status that does not exist instead of returning nothing', function () {
    $this->actingAs(subsListAdmin(), 'sanctum')
        ->getJson('/api/admin/subscriptions?status=paused')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status.0');
});

it('filters by plan, method, cycle and market', function () {
    $pro = subsListPlan('Pro');
    $one = subsListPlan('One');

    Market::create([
        'code' => 'ID', 'name' => 'Indonesia', 'currency' => 'IDR', 'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta', 'phone_country' => '62', 'status' => 'draft',
    ]);

    $proCard = subsListRow(subsListTenant(), $pro, ['payment_method' => PaymentMethod::Card]);
    $oneYearly = subsListRow(subsListTenant(), $one, ['billing_cycle' => BillingCycle::Yearly]);
    $indonesia = subsListRow(subsListTenant(market: 'ID'), $one, [
        'payment_method' => PaymentMethod::Checkout,
        'currency' => 'IDR',
    ]);

    $admin = subsListAdmin();
    $list = fn (string $query) => subsListIds($this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/subscriptions?'.$query)
        ->assertOk());

    expect($list("plan_id={$pro->id}"))->toBe([$proCard->id])
        ->and($list('payment_method=card,checkout'))->toBe([$proCard->id, $indonesia->id])
        ->and($list('billing_cycle=yearly'))->toBe([$oneYearly->id])
        ->and($list('market=id'))->toBe([$indonesia->id])
        ->and($list("plan_id={$one->id}&market=BR"))->toBe([$oneYearly->id]);
});

it('keeps only the row each workspace is on today when asked', function () {
    $plan = subsListPlan('Pro');
    $tenant = subsListTenant();

    // An upgrade leaves the old row behind, cancelled.
    $old = subsListRow($tenant, $plan, ['status' => SubscriptionStatus::Cancelled], current: false);
    $live = subsListRow($tenant, $plan);

    $admin = subsListAdmin();

    expect(subsListIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/subscriptions')))
        ->toBe([$old->id, $live->id])
        ->and(subsListIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/subscriptions?current=1')))
        ->toBe([$live->id]);
});

it('serves what the filters pick from, under the list permission', function () {
    $retired = subsListPlan('Legacy');
    $retired->forceFill(['is_active' => false])->save();
    subsListPlan('Pro');

    $this->actingAs(subsListAdmin(), 'sanctum')
        ->getJson('/api/admin/subscriptions/meta')
        ->assertOk()
        ->assertJsonPath('data.statuses', array_map(fn ($s) => $s->value, SubscriptionStatus::cases()))
        ->assertJsonPath('data.payment_methods', ['card', 'pix', 'checkout', 'manual'])
        // A retired plan still has subscribers to find.
        ->assertJsonFragment(['name' => 'Legacy', 'is_active' => false])
        ->assertJsonFragment(['code' => 'BR']);
});

it('tells the row which country the workspace is in', function () {
    subsListRow(subsListTenant(), subsListPlan('Pro'));

    $this->actingAs(subsListAdmin(), 'sanctum')
        ->getJson('/api/admin/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.0.tenant.market_code', 'BR');
});

it('requires the subscriptions permission', function () {
    $this->actingAs(Admin::factory()->create(), 'sanctum')
        ->getJson('/api/admin/subscriptions/meta')
        ->assertForbidden();
});
