<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\Connection\Meta\InstagramConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function settingsAdmin(): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.settings.manage', 'web'));

    $admin = User::factory()->create(['tenant_id' => null]);
    $admin->assignRole($role);

    return $admin;
}

test('the settings payload no longer exposes a facebook redirect uri', function () {
    $this->actingAs(settingsAdmin(), 'sanctum')
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonMissingPath('data.facebook.redirect_uri');
});

test('a facebook redirect uri sent by an old client is ignored, not stored', function () {
    $this->actingAs(settingsAdmin(), 'sanctum')
        ->putJson('/api/admin/settings', [
            'facebook' => [
                'app_id' => '123',
                'redirect_uri' => 'https://example.com/callback',
            ],
        ])->assertOk();

    expect(Setting::get('facebook.redirect_uri'))->toBeNull();
    expect(DB::table('settings')->where('key', 'facebook.redirect_uri')->exists())->toBeFalse();
});

test('the instagram redirect uri is untouched', function () {
    $admin = settingsAdmin();

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', [
            'instagram' => ['redirect_uri' => 'https://example.com/instagram/callback'],
        ])->assertOk();

    expect(InstagramConfig::redirectUri())->toBe('https://example.com/instagram/callback');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/settings')
        ->assertJsonPath('data.instagram.redirect_uri', 'https://example.com/instagram/callback');
});
