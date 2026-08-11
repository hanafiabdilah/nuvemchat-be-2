<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function uiPrefsUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

it('stores the chosen theme and appearance for the user', function () {
    $user = uiPrefsUser();

    $this->actingAs($user)
        ->putJson('/api/user/preferences', ['theme' => 'studio', 'appearance' => 'dark'])
        ->assertOk()
        ->assertJsonPath('ui_preferences.theme', 'studio')
        ->assertJsonPath('ui_preferences.appearance', 'dark');

    expect($user->fresh()->ui_preferences)->toBe(['theme' => 'studio', 'appearance' => 'dark']);
});

it('merges a partial update instead of dropping the other key', function () {
    $user = uiPrefsUser();
    $user->forceFill(['ui_preferences' => ['theme' => 'studio', 'appearance' => 'dark']])->save();

    $this->actingAs($user)
        ->putJson('/api/user/preferences', ['appearance' => 'light'])
        ->assertOk();

    expect($user->fresh()->ui_preferences)->toBe(['theme' => 'studio', 'appearance' => 'light']);
});

it('rejects an unknown theme', function () {
    $user = uiPrefsUser();

    $this->actingAs($user)
        ->putJson('/api/user/preferences', ['theme' => 'neon'])
        ->assertStatus(422);

    expect($user->fresh()->ui_preferences)->toBeNull();
});

it('exposes the preferences on the user payload the app boots from', function () {
    $user = uiPrefsUser();
    $user->forceFill(['ui_preferences' => ['theme' => 'studio', 'appearance' => 'system']])->save();

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.ui_preferences.theme', 'studio');
});
