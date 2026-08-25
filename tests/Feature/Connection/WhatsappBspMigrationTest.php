<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const MIGRATION_WABA_ID = '900900900900900';
const MIGRATION_PHONE_ID = '800800800800800';

function migrationConnection(): Connection
{
    Setting::set('facebook.app_id', 'app-id');
    Setting::set('facebook.app_secret', 'app-secret');

    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Pending,
    ]);
}

/**
 * Meta's answers for a number arriving from another BSP: already verified, and
 * — the trap this whole feature turns on — already reporting CLOUD_API.
 *
 * @param  array<string, mixed>  $phoneOverrides
 * @param  array<string, mixed>|null  $registerError  null = registration succeeds
 */
function fakeMigrationGraph(array $phoneOverrides = [], ?array $registerError = null): void
{
    $phone = array_merge([
        'id' => MIGRATION_PHONE_ID,
        'display_phone_number' => '+55 11 99999-0000',
        'verified_name' => 'ProxyBR',
        'quality_rating' => 'GREEN',
        'code_verification_status' => 'VERIFIED',
        'platform_type' => 'CLOUD_API',
        'is_pin_enabled' => false,
        'is_on_biz_app' => false,
    ], $phoneOverrides);

    Http::fake([
        'graph.facebook.com/v25.0/oauth/access_token' => Http::response(['access_token' => 'system-user-token']),
        'graph.facebook.com/v25.0/' . MIGRATION_PHONE_ID . '/register' => $registerError
            ? Http::response(['error' => $registerError], 400)
            : Http::response(['success' => true]),
        'graph.facebook.com/v25.0/' . MIGRATION_WABA_ID . '/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/v25.0/' . MIGRATION_PHONE_ID . '*' => Http::response($phone),
    ]);
}

function migrationCallback(Connection $connection, bool $isMigration): \Illuminate\Testing\TestResponse
{
    $state = base64_encode(json_encode([
        'connection_id' => $connection->id,
        'waba_id' => MIGRATION_WABA_ID,
        'phone_number_id' => MIGRATION_PHONE_ID,
        'fb_user_id' => '123456',
        'is_migration' => $isMigration,
    ]));

    return test()->get('/oauth/facebook/callback?' . http_build_query(['code' => 'oauth-code', 'state' => $state]));
}

/** Did we ask Meta to register the number on our WABA? */
function registerCalled(): bool
{
    foreach (Http::recorded() as [$request, $response]) {
        if (str_ends_with($request->url(), '/register')) {
            return true;
        }
    }

    return false;
}

test('a migrated number is registered even though Meta already reports it as verified on Cloud API', function () {
    fakeMigrationGraph();
    $connection = migrationConnection();

    migrationCallback($connection, isMigration: true)->assertOk();

    // Registration is per-WABA. The number has been live at the losing BSP for
    // months, so it arrives VERIFIED and CLOUD_API — evidence that would make
    // the normal onboarding skip the call and leave a connection that looks
    // healthy and cannot send a thing.
    expect(registerCalled())->toBeTrue();

    $credentials = $connection->fresh()->credentials;
    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active)
        ->and($credentials['migrated_from_bsp'])->toBeTrue()
        ->and($credentials['migrated_at'])->not->toBeNull()
        ->and($credentials['pin'])->not->toBeNull();
});

test('an ordinary re-authorization of an already-registered number still skips registration', function () {
    fakeMigrationGraph();
    $connection = migrationConnection();

    migrationCallback($connection, isMigration: false)->assertOk();

    // The skip exists because /register is not safely re-runnable against a
    // PIN we do not hold. Migration is the exception, not the new rule.
    expect(registerCalled())->toBeFalse()
        ->and($connection->fresh()->credentials['migrated_from_bsp'])->toBeFalse();
});

test('two-step verification still on at the old provider fails before Meta is asked', function () {
    fakeMigrationGraph(['is_pin_enabled' => true]);
    $connection = migrationConnection();

    $response = migrationCallback($connection, isMigration: true);

    $response->assertStatus(500);
    expect($response->json('message'))->toContain('Two-step verification is still enabled');

    // Nothing was attempted: Meta would have answered with a PIN mismatch that
    // reads like our bug, and the connection would be half-built.
    expect(registerCalled())->toBeFalse()
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Pending);
});

test('a PIN mismatch from Meta is translated into the fix the business can carry out', function () {
    // 2FA that is_pin_enabled failed to report — the same cause, found later.
    fakeMigrationGraph(
        ['is_pin_enabled' => false],
        ['message' => 'Registration failed', 'code' => 133005, 'error_subcode' => 0],
    );
    $connection = migrationConnection();

    $response = migrationCallback($connection, isMigration: true);

    $response->assertStatus(500);
    expect($response->json('message'))->toContain('two-step verification')
        ->and($response->json('message'))->toContain('run the migration again');
});

test('a migration that creates an empty WABA keeps it and waits for the number', function () {
    // Embedded Signup made the destination account and the customer added no
    // number to it — correct for a migration, since the number they want is
    // still live at the other provider. This used to throw "No phone numbers
    // found", losing the WABA and stranding the flow with nowhere to put it.
    Http::fake([
        'graph.facebook.com/v25.0/oauth/access_token' => Http::response(['access_token' => 'system-user-token']),
        'graph.facebook.com/v25.0/' . MIGRATION_WABA_ID . '/phone_numbers' => Http::response(['data' => []]),
        'graph.facebook.com/v25.0/' . MIGRATION_WABA_ID . '/subscribed_apps' => Http::response(['success' => true]),
    ]);

    $connection = migrationConnection();

    $state = base64_encode(json_encode([
        'connection_id' => $connection->id,
        'waba_id' => MIGRATION_WABA_ID,
        'fb_user_id' => '123456',
        'is_migration' => true,
    ]));

    test()->get('/oauth/facebook/callback?' . http_build_query(['code' => 'oauth-code', 'state' => $state]))
        ->assertOk();

    $credentials = $connection->fresh()->credentials;

    expect($credentials['business_account_id'])->toBe(MIGRATION_WABA_ID)
        ->and($credentials['access_token'])->toBe('system-user-token')
        // Still Pending: nothing can be sent until the number arrives, and
        // showing Active here is the failure mode this whole feature exists
        // to avoid.
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Pending);
});

test('an ordinary connect with no numbers still fails loudly', function () {
    Http::fake([
        'graph.facebook.com/v25.0/oauth/access_token' => Http::response(['access_token' => 'system-user-token']),
        'graph.facebook.com/v25.0/' . MIGRATION_WABA_ID . '/phone_numbers' => Http::response(['data' => []]),
    ]);

    $connection = migrationConnection();

    // Outside a migration an empty WABA means the onboarding did not finish —
    // the tolerance above is deliberately scoped to migrations only.
    migrationCallback($connection, isMigration: false)->assertStatus(500);
});

test('a number Meta says is already registered completes instead of failing', function () {
    fakeMigrationGraph(
        [],
        ['message' => 'Phone number already registered', 'code' => 133010, 'error_subcode' => 2388023],
    );
    $connection = migrationConnection();

    migrationCallback($connection, isMigration: true)->assertOk();

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active);
});
