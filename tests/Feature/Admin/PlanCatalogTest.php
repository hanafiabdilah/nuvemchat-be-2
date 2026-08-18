<?php

use App\Enums\Billing\Feature;
use App\Enums\Billing\Quota;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function planAdmin(): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.plans.manage', 'web'));

    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole($role);

    return $user;
}

function planPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Business',
        'price_cents' => 19900,
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true, 'flow' => true, 'crm' => true],
        'quotas' => ['max_connections' => 5, 'max_agents' => 10],
    ], $overrides);
}

test('the catalogue endpoint lists every feature and quota the enums define', function () {
    $res = $this->actingAs(planAdmin(), 'sanctum')->getJson('/api/admin/plans/meta')->assertOk();

    expect(collect($res->json('data.features'))->pluck('key')->all())->toBe(Feature::values());
    expect(collect($res->json('data.quotas'))->pluck('key')->all())->toBe(Quota::values());

    // The Back Office renders these strings, so they have to arrive with the keys.
    expect($res->json('data.features.0.label'))->not->toBeEmpty();
    expect($res->json('data.quotas.0.enforced_at'))->not->toBeEmpty();
});

test('crm survives a round trip through the plan editor', function () {
    // The regression this whole endpoint exists for: the editor sent the whole
    // features object built from its own hardcoded list, `crm` was not on it,
    // and saving any plan switched the funnel off for that plan's customers.
    $admin = planAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/plans', planPayload())
        ->assertCreated();

    $plan = Plan::firstWhere('name', 'Business');
    expect($plan->features['crm'])->toBeTrue();

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/admin/plans/{$plan->id}", planPayload(['price_cents' => 24900]))
        ->assertOk();

    expect($plan->fresh()->features['crm'])->toBeTrue();
});

test('an unknown feature key is rejected instead of being stored forever', function () {
    $this->actingAs(planAdmin(), 'sanctum')
        ->postJson('/api/admin/plans', planPayload([
            'features' => ['chat' => true, 'teleportation' => true],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('features');
});

test('an unknown quota key is rejected', function () {
    $this->actingAs(planAdmin(), 'sanctum')
        ->postJson('/api/admin/plans', planPayload([
            'quotas' => ['max_connections' => 5, 'max_proxies' => 3],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('quotas');
});

test('a blank quota means unlimited rather than zero', function () {
    $this->actingAs(planAdmin(), 'sanctum')
        ->postJson('/api/admin/plans', planPayload([
            'quotas' => ['max_connections' => null, 'max_agents' => 10],
        ]))
        ->assertCreated();

    $plan = Plan::firstWhere('name', 'Business');

    expect($plan->quotas['max_connections'])->toBeNull();
    expect($plan->quotas['max_agents'])->toBe(10);
});
