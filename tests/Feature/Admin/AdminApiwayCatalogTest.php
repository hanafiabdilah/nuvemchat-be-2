<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The "Test connection" probe on Back Office → Integrations → ProxyBR.
 *
 * Its whole job is to report that our partner token is wrong, so the one
 * status it must never answer with is the one that means "your session is
 * wrong": the SPA reads a 401 as an expired session and bounces the admin to
 * the login screen — out of the very tab where the token is fixed, and before
 * the toast explaining why can be read.
 */
uses(RefreshDatabase::class);

function adminCatalogProber(): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.settings.manage', 'web'));

    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

test('a partner token ProxyBR rejects is reported as a gateway failure, never as 401', function () {
    Http::fake([
        '*/api/partner/v1/apiway/plans' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $this->actingAs(adminCatalogProber(), 'sanctum')
        ->getJson('/api/admin/apiway/catalog')
        ->assertStatus(502)
        ->assertJsonPath('code', 'apiway_unauthorized')
        ->assertJsonPath('upstream_status', 401)
        // The bare "Unauthenticated." is what read like the admin's own
        // session dying; whose credential was refused has to be in the text.
        ->assertJsonFragment(['message' => 'ProxyBR rejected our partner token: Unauthenticated.']);
});

test('a partner token ProxyBR forbids is reported the same way', function () {
    Http::fake([
        '*/api/partner/v1/apiway/plans' => Http::response(['error' => 'partner_api_disabled', 'message' => 'Partner API disabled.'], 403),
    ]);

    $this->actingAs(adminCatalogProber(), 'sanctum')
        ->getJson('/api/admin/apiway/catalog')
        ->assertStatus(502)
        // The partner's own code survives when it sends one — it says more
        // than our fallback does.
        ->assertJsonPath('code', 'partner_api_disabled')
        ->assertJsonPath('upstream_status', 403);
});

test('an upstream outage stays a 502', function () {
    Http::fake([
        '*/api/partner/v1/apiway/plans' => Http::response(['message' => 'Server error.'], 500),
    ]);

    $this->actingAs(adminCatalogProber(), 'sanctum')
        ->getJson('/api/admin/apiway/catalog')
        ->assertStatus(502)
        ->assertJsonPath('code', 'apiway_unavailable');
});

test('a healthy partner answers the catalog', function () {
    Http::fake([
        '*/api/partner/v1/apiway/plans' => Http::response(['data' => [
            'settings' => ['min_instances' => 1, 'max_instances' => 20],
            'tiers' => [['id' => 1, 'min_qty' => 1, 'unit_price_monthly' => 49.9]],
            'locations' => [['id' => 1, 'public_code' => 'br', 'label' => 'Brasil', 'active' => true]],
        ]]),
    ]);

    $this->actingAs(adminCatalogProber(), 'sanctum')
        ->getJson('/api/admin/apiway/catalog')
        ->assertOk()
        ->assertJsonCount(1, 'data.tiers');
});

test('an unconfigured token is still its own 503, not an auth failure', function () {
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, null);

    $this->actingAs(adminCatalogProber(), 'sanctum')
        ->getJson('/api/admin/apiway/catalog')
        ->assertStatus(503)
        ->assertJsonPath('code', 'apiway_unconfigured');
});
