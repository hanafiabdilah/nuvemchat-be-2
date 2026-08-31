<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function apiwaySettingsAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.settings.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

test('the partner token is masked on read and never echoed back', function () {
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-secret-token-1234');

    $this->actingAs(apiwaySettingsAdmin(), 'sanctum')
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('data.apiway.partner_token_set', true)
        ->assertJsonPath('data.apiway.partner_base_url', ApiwayConfig::DEFAULT_PARTNER_BASE_URL)
        ->assertJsonMissing(['partner_token' => 'partner-secret-token-1234']);
});

test('saving with a blank token keeps the stored one; a new value replaces it', function () {
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'original-token');
    $admin = apiwaySettingsAdmin();

    // Blank token → keep.
    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', [
            'apiway' => [
                'base_url' => 'https://whats-api.ipbr.pro',
                'partner_base_url' => 'https://portal.proxybr.com.br',
                'partner_token' => '',
            ],
        ])->assertOk();

    expect(ApiwayConfig::partnerToken())->toBe('original-token');

    // New value → replace.
    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', [
            'apiway' => [
                'base_url' => 'https://whats-api.ipbr.pro',
                'partner_token' => 'rotated-token',
            ],
        ])->assertOk();

    expect(ApiwayConfig::partnerToken())->toBe('rotated-token');
});

test('the partner base url is normalized and falls back to the default when cleared', function () {
    $admin = apiwaySettingsAdmin();

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', [
            'apiway' => [
                'base_url' => 'https://whats-api.ipbr.pro',
                'partner_base_url' => 'https://staging.proxybr.com.br/',
            ],
        ])->assertOk();

    expect(ApiwayConfig::partnerBaseUrl())->toBe('https://staging.proxybr.com.br');

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', [
            'apiway' => [
                'base_url' => 'https://whats-api.ipbr.pro',
                'partner_base_url' => null,
            ],
        ])->assertOk();

    expect(ApiwayConfig::partnerBaseUrl())->toBe(ApiwayConfig::DEFAULT_PARTNER_BASE_URL);
});
