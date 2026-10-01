<?php

use App\Enums\Billing\BillingCycle;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('reports a meter for every quota a plan can carry', function () {
    Bus::fake();

    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Usage',
        'slug' => 'usage-'.uniqid(),
        'price_cents' => 1000,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
        'quotas' => ['max_connections' => 3, 'included_instances' => 2, 'included_trained_agents' => 1, 'gallery_storage_gb' => 5],
    ]);

    $admin = Admin::query()->create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('secret-pass')]);
    $subscription = app(BillingService::class)->grantManual($tenant->fresh(), $plan, null, $admin);

    $usage = (new SubscriptionResource($subscription->fresh()))->withUsage()->resolve(request())['usage'];

    expect($usage)->toHaveKeys([
        'connections', 'agents', 'included_instances', 'included_trained_agents', 'gallery_bytes', 'gallery_limit_bytes',
    ])
        ->and($usage['agents'])->toBe(1)
        ->and($usage['included_instances'])->toBe(0)
        ->and($usage['included_trained_agents'])->toBe(0)
        ->and($usage['gallery_bytes'])->toBe(0)
        ->and($usage['gallery_limit_bytes'])->toBe(5 * 1024 ** 3);
});
