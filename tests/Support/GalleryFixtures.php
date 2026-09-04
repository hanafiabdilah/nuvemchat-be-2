<?php

namespace Tests\Support;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\CreditWallet;
use App\Models\GalleryAsset;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Gallery\GalleryStorage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Shared setup for the gallery suite.
 *
 * A tenant here gets a *usable subscription*, and that is not incidental
 * scaffolding: SubscriptionGate only merges a plan's quotas when a live
 * subscription is behind them, and gallery space reads absence as zero rather
 * than unlimited. A fixture that skipped the subscription would silently build
 * a workspace with no space at all and let the wrong assertions pass.
 */
class GalleryFixtures
{
    /** A workspace with $planGb of included storage and $balanceCents in the wallet. */
    public static function tenant(int $planGb = 0, int $balanceCents = 0): Tenant
    {
        foreach (['gallery.view', 'gallery.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create([
            'email' => 'gallery-'.uniqid().'@example.test',
            'whatsapp_verified_at' => now(),
        ]);
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->givePermissionTo(['gallery.view', 'gallery.manage']);

        $quotas = ['gallery_storage_gb' => $planGb];

        $plan = Plan::create([
            'name' => 'Gallery test',
            'slug' => 'gallery-test-'.Str::random(8),
            'price_cents' => 9900,
            'currency' => 'BRL',
            'billing_cycle' => BillingCycle::Monthly,
            'quotas' => $quotas,
            'features' => ['chat' => true],
            'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'price_cents' => 9900,
            'quotas_snapshot' => $quotas,
            'features_snapshot' => ['chat' => true],
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);

        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

        if ($balanceCents !== 0) {
            CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => $balanceCents, 'currency' => 'BRL']);
        }

        $tenant = $tenant->fresh();

        // Entitlements are cached for a minute, and each test builds a fresh
        // tenant that would otherwise read the previous one's.
        app(SubscriptionGate::class)->forget($tenant);

        return $tenant;
    }

    /**
     * An asset row of a given size, without going through an upload.
     *
     * For tests about the meter rather than about storing files: what the quota
     * arithmetic does with 3 GB already in the library is the same question
     * whether or not 3 GB of bytes exist on the test disk.
     */
    public static function asset(Tenant $tenant, int $sizeBytes, string $type = 'image'): GalleryAsset
    {
        return GalleryAsset::create([
            'tenant_id' => $tenant->id,
            'uuid' => (string) Str::uuid(),
            'public_filename' => 'arquivo.jpg',
            'name' => 'arquivo.jpg',
            'path' => 'gallery/'.$tenant->id.'/'.Str::random(12).'.jpg',
            'mime_type' => 'image/jpeg',
            'type' => $type,
            'size_bytes' => $sizeBytes,
            'checksum' => hash('sha256', Str::random(32)),
        ]);
    }

    public static function gb(int $count): int
    {
        return $count * GalleryStorage::BYTES_PER_GB;
    }
}
