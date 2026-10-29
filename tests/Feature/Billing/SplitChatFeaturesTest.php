<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\Feature;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Instagram, TikTok and YouTube management and campaigns used to come with
 * `chat`. Each is a plan feature of its own now, so a plan can sell them
 * separately — and the migration that split them must not take them away from
 * anyone who had them through `chat`.
 */
function splitPlanUser(array $features, array $permissions): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Plano', 'slug' => 'plano-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'features' => $features, 'quotas' => [],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        'quotas_snapshot' => [], 'features_snapshot' => $features,
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    $role = Role::findOrCreate('split-'.$tenant->id, 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user->assignRole($role);

    return $user->fresh();
}

dataset('split surfaces', [
    'instagram' => ['instagram_management', '/api/instagram/accounts', 'instagram-posts.view'],
    'tiktok' => ['tiktok_management', '/api/tiktok/accounts', 'tiktok-posts.view'],
    'youtube' => ['youtube_management', '/api/youtube/accounts', 'youtube-posts.view'],
    'campaigns' => ['campaigns', '/api/broadcasts', 'broadcasts.view'],
]);

it('refuses the surface to a plan that has the inbox but not its feature', function (string $feature, string $url, string $permission) {
    config(['services.billing.enforce' => true]);

    $user = splitPlanUser(['chat' => true], [$permission]);

    $this->actingAs($user, 'sanctum')
        ->getJson($url)
        ->assertForbidden()
        ->assertJsonPath('code', 'feature_not_in_plan')
        ->assertJsonPath('feature', $feature);
})->with('split surfaces');

it('opens the surface to a plan that sells it', function (string $feature, string $url, string $permission) {
    config(['services.billing.enforce' => true]);

    $user = splitPlanUser(['chat' => true, $feature => true], [$permission]);

    $this->actingAs($user, 'sanctum')->getJson($url)->assertOk();
})->with('split surfaces');

it('keeps the feature an add-on of the inbox, not a replacement for it', function () {
    config(['services.billing.enforce' => true]);

    $user = splitPlanUser(['instagram_management' => true], ['instagram-posts.view']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/instagram/accounts')
        ->assertForbidden()
        ->assertJsonPath('feature', 'chat');
});

it('refuses sending to an inbox selection without campaigns, before anything is validated', function () {
    // The same engine as a campaign, reached from the inbox: it must not be a
    // way round the feature.
    config(['services.billing.enforce' => true]);

    $user = splitPlanUser(['chat' => true], ['broadcasts.create', 'broadcasts.send']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/broadcasts/conversations', [])
        ->assertForbidden()
        ->assertJsonPath('feature', 'campaigns');
});

it('refuses comment marks for a network the plan does not sell', function () {
    // These routes serve all three networks, so the route-group middleware
    // does not reach them; the controller checks the network named in the URL.
    config(['services.billing.enforce' => true]);

    $user = splitPlanUser(['chat' => true, 'youtube_management' => true], ['instagram-comments.manage']);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/social/instagram/accounts/1/comments/abc/marks', ['read' => true])
        ->assertForbidden()
        ->assertJsonPath('feature', 'instagram_management');
});

it('gives every plan, snapshot and override that has the inbox the features split out of it', function () {
    $keys = array_map(fn (Feature $f) => $f->value, Feature::splitFromChat());

    $withChat = splitPlanUser(['chat' => true, 'flow' => true], []);
    $withoutChat = splitPlanUser(['whatsapp_api' => true], []);
    // Someone already took one away in the Back Office: left as they set it.
    $edited = splitPlanUser(['chat' => true, 'campaigns' => false], []);

    $withChat->tenant->forceFill(['entitlement_overrides' => ['features' => ['chat' => true]]])->save();

    (include database_path('migrations/2026_10_29_000700_split_social_and_campaign_features_from_chat.php'))->up();

    $planFeatures = fn (User $user) => json_decode(DB::table('plans')->where('id', $user->tenant->currentSubscription->plan_id)->value('features'), true);
    $snapshot = fn (User $user) => json_decode(DB::table('subscriptions')->where('id', $user->tenant->current_subscription_id)->value('features_snapshot'), true);

    foreach ($keys as $key) {
        expect($planFeatures($withChat)[$key])->toBeTrue()
            ->and($snapshot($withChat)[$key])->toBeTrue()
            ->and($planFeatures($withoutChat))->not->toHaveKey($key)
            ->and($snapshot($withoutChat))->not->toHaveKey($key);
    }

    expect($withChat->tenant->fresh()->entitlement_overrides['features']['instagram_management'])->toBeTrue()
        ->and($snapshot($edited)['campaigns'])->toBeFalse()
        ->and($snapshot($edited)['tiktok_management'])->toBeTrue();
});
