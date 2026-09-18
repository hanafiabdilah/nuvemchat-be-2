<?php

use App\Enums\Notification\NotificationType;
use App\Models\Admin;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notification\NotificationConfig;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** A Back Office admin: an `admins` row holding a platform role. */
function boAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.settings.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

test('the settings payload exposes every notification event with its editable template', function () {
    $res = $this->actingAs(boAdmin(), 'sanctum')->getJson('/api/admin/settings')->assertOk();

    $values = collect($res->json('data.notifications.event_types'))->pluck('value')->all();

    // Every enum case must reach the Back Office editor, or it cannot be customized.
    expect($values)->toBe(array_map(fn ($c) => $c->value, NotificationType::cases()));
    expect($values)->toContain('password_reset_otp', 'password_changed', 'whatsapp_otp');

    $reset = collect($res->json('data.notifications.event_types'))->firstWhere('value', 'password_reset_otp');
    expect($reset['required'])->toBeTrue();
    expect($reset['placeholders'])->toBe(['name', 'code', 'ttl']);
    expect($reset['default_template'])->toBe(NotificationType::PasswordResetOtp->defaultTemplate());
});

test('an admin can save a custom template and it is what actually gets sent', function () {
    $admin = boAdmin();

    $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
        'notifications' => [
            'templates' => [
                'password_reset_otp' => 'Codigo {{code}} para {{name}} ({{ttl}}min)',
            ],
        ],
    ])->assertOk()
        // Stored per language now. This payload has no language in it — it is
        // exactly what a Back Office build from before languages sends — and it
        // is claimed for the platform's own rather than dropped or spread
        // across all three: an override typed in Portuguese is a Portuguese
        // override, whoever ends up reading it.
        ->assertJsonPath(
            'data.notifications.templates.password_reset_otp.'.config('markets.default_locale'),
            'Codigo {{code}} para {{name}} ({{ttl}}min)',
        );

    expect(app(NotificationService::class)->render(NotificationType::PasswordResetOtp, [
        'name' => 'Ana', 'code' => '654321', 'ttl' => '10',
    ]))->toBe('Codigo 654321 para Ana (10min)');
});

test('clearing an override falls back to the default body', function () {
    $admin = boAdmin();
    Setting::set(NotificationConfig::KEY_TEMPLATES, json_encode(['password_changed' => 'custom']));

    $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
        'notifications' => ['templates' => ['password_changed' => '']],
    ])->assertOk();

    expect(NotificationConfig::template(NotificationType::PasswordChanged))
        ->toBe(NotificationType::PasswordChanged->defaultTemplate());
});

/**
 * The Back Office splits this settings block across two screens: Message Templates
 * owns `templates`, Integrations → Notifications owns the provider credentials and
 * `events`. Each saves only its own keys, so neither may clobber the other's.
 */
test('saving only the templates leaves the provider credentials intact', function () {
    $admin = boAdmin();
    Setting::set(NotificationConfig::KEY_PINGLY_API_KEY, 'pk_secret');
    Setting::set(NotificationConfig::KEY_PINGLY_CONNECTION_ID, 'conn_abc123');
    Setting::set(NotificationConfig::KEY_PROVIDER, 'proxybr');

    $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
        'notifications' => ['templates' => ['welcome_registration' => 'Oi {{name}}']],
    ])->assertOk();

    expect(NotificationConfig::pinglyApiKey())->toBe('pk_secret');
    expect(NotificationConfig::pinglyConnectionId())->toBe('conn_abc123');
    expect(NotificationConfig::provider())->toBe('proxybr');
});

test('saving the credentials and toggles leaves the custom templates intact', function () {
    $admin = boAdmin();
    Setting::set(NotificationConfig::KEY_TEMPLATES, json_encode(['welcome_registration' => 'Oi {{name}}']));

    // Exactly what the Integrations tab sends: no `templates` key at all.
    $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
        'notifications' => [
            'enabled' => true,
            'provider' => 'pingly',
            'events' => ['welcome_registration' => false],
        ],
    ])->assertOk();

    expect(NotificationConfig::template(NotificationType::WelcomeRegistration))->toBe('Oi {{name}}');
    expect(NotificationConfig::eventEnabled(NotificationType::WelcomeRegistration))->toBeFalse();
});

test('a non-admin cannot read or write the templates', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/admin/settings')->assertForbidden();
    $this->actingAs($user, 'sanctum')->putJson('/api/admin/settings', [
        'notifications' => ['templates' => ['password_reset_otp' => 'hacked']],
    ])->assertForbidden();
});
