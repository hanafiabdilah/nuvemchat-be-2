<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const MIG_WABA = '111222333';
const MIG_PHONE_ID = '444555666';

/** A connection that already has a WABA — what Embedded Signup leaves behind. */
function migrationConnectionWithWaba(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('migrator-' . $tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate('connections.connect', 'web'));
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Pending,
        'credentials' => [
            'access_token' => 'system-user-token',
            'business_account_id' => MIG_WABA,
            'fb_user_id' => '999',
        ],
    ]);
}

function graphUrl(string $path): string
{
    return 'graph.facebook.com/v25.0/' . $path;
}

/**
 * Every call the four steps make, all succeeding unless overridden.
 *
 * `+` rather than array_merge: both sides use the same string keys, and
 * array_merge would let the defaults overwrite the override the test just
 * asked for.
 */
function fakeNumberMigrationGraph(array $overrides = []): void
{
    Http::fake($overrides + [
        graphUrl(MIG_WABA . '/phone_numbers') => Http::response(['id' => MIG_PHONE_ID]),
        graphUrl(MIG_PHONE_ID . '/request_code') => Http::response(['success' => true]),
        graphUrl(MIG_PHONE_ID . '/verify_code') => Http::response(['success' => true]),
        graphUrl(MIG_PHONE_ID . '/register') => Http::response(['success' => true]),
        graphUrl(MIG_WABA . '/subscribed_apps') => Http::response(['success' => true]),
        graphUrl(MIG_PHONE_ID . '*') => Http::response([
            'id' => MIG_PHONE_ID,
            'display_phone_number' => '+55 11 99999-0000',
            'verified_name' => 'ProxyBR',
            'quality_rating' => 'GREEN',
        ]),
    ]);
}

function claimNumber(Connection $c, array $body = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/connections/{$c->id}/migration/phone-number", array_merge([
        'cc' => '55',
        'phone_number' => '11999990000',
        'verified_name' => 'ProxyBR',
    ], $body));
}

test('claiming the number sends Meta the migrate flag and remembers the new id', function () {
    fakeNumberMigrationGraph();
    $connection = migrationConnectionWithWaba();

    claimNumber($connection)->assertOk()->assertJsonPath('data.phone_number_id', MIG_PHONE_ID);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/phone_numbers')) {
            return false;
        }
        $body = $request->data();

        // migrate_phone_number is what separates "take this number over" from
        // "create a brand new one" — without it Meta refuses a number that
        // already lives somewhere.
        return $body['migrate_phone_number'] === true
            && $body['cc'] === '55'
            && $body['phone_number'] === '11999990000'
            && $body['verified_name'] === 'ProxyBR';
    });

    // Kept so a refresh between the code being sent and typed does not strand
    // a number that has already been claimed.
    expect($connection->fresh()->credentials['pending_migration']['phone_number_id'])->toBe(MIG_PHONE_ID);
});

test('a number still held by the old provider is refused with the fix, not a Meta code', function () {
    fakeNumberMigrationGraph([
        graphUrl(MIG_WABA . '/phone_numbers') => Http::response([
            'error' => ['message' => 'Phone number already exists', 'code' => 100],
        ], 409),
    ]);
    $connection = migrationConnectionWithWaba();

    $response = claimNumber($connection);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('two-step verification');

    // Nothing half-written: the next attempt starts clean.
    expect($connection->fresh()->credentials)->not->toHaveKey('pending_migration');
});

test('the verification code cannot be requested before the number is claimed', function () {
    fakeNumberMigrationGraph();
    $connection = migrationConnectionWithWaba();

    test()->postJson("/api/connections/{$connection->id}/migration/request-code", ['code_method' => 'SMS'])
        ->assertStatus(422);
});

test('requesting the code passes the chosen delivery method through', function () {
    fakeNumberMigrationGraph();
    $connection = migrationConnectionWithWaba();
    claimNumber($connection);

    test()->postJson("/api/connections/{$connection->id}/migration/request-code", ['code_method' => 'VOICE'])
        ->assertOk();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/request_code')
        && $request->data()['code_method'] === 'VOICE'
        && $request->data()['language'] === 'en_US');
});

test('a code Meta cannot deliver names the likely cause', function () {
    fakeNumberMigrationGraph([
        graphUrl(MIG_PHONE_ID . '/request_code') => Http::response([
            'error' => ['message' => 'Request code error', 'code' => 136024],
        ], 400),
    ]);
    $connection = migrationConnectionWithWaba();
    claimNumber($connection);

    $response = test()->postJson("/api/connections/{$connection->id}/migration/request-code", ['code_method' => 'SMS']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('release it first');
});

test('verifying the code registers the number and finishes the connection', function () {
    fakeNumberMigrationGraph();
    $connection = migrationConnectionWithWaba();
    claimNumber($connection);
    test()->postJson("/api/connections/{$connection->id}/migration/request-code", ['code_method' => 'SMS']);

    test()->postJson("/api/connections/{$connection->id}/migration/verify-code", ['code' => '123-456'])
        ->assertOk();

    // Meta sends the code hyphenated and refuses it back that way.
    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/verify_code')
        || $request->data()['code'] === '123456');

    $fresh = $connection->fresh();
    expect($fresh->status)->toBe(ConnectionStatus::Active)
        ->and($fresh->credentials['phone_number_id'])->toBe(MIG_PHONE_ID)
        ->and($fresh->credentials['migrated_from_bsp'])->toBeTrue()
        ->and($fresh->credentials['pin'])->not->toBeNull()
        // The scratch state is cleared, or a later refresh would offer to
        // resume a migration that already finished.
        ->and($fresh->credentials)->not->toHaveKey('pending_migration');
});

test('two-step verification still on at the old provider is reported at the register step', function () {
    fakeNumberMigrationGraph([
        graphUrl(MIG_PHONE_ID . '/register') => Http::response([
            'error' => ['message' => 'PIN mismatch', 'code' => 133005],
        ], 400),
    ]);
    $connection = migrationConnectionWithWaba();
    claimNumber($connection);

    $response = test()->postJson("/api/connections/{$connection->id}/migration/verify-code", ['code' => '123456']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('two-step verification')
        // The number is already ours by this point; only the last step repeats.
        ->and($response->json('message'))->toContain('only this step repeats');

    expect($connection->fresh()->credentials['pending_migration']['phone_number_id'])->toBe(MIG_PHONE_ID);
});

test('an already-registered number completes rather than failing', function () {
    fakeNumberMigrationGraph([
        graphUrl(MIG_PHONE_ID . '/register') => Http::response([
            'error' => ['message' => 'already registered', 'code' => 133010, 'error_subcode' => 2388023],
        ], 400),
    ]);
    $connection = migrationConnectionWithWaba();
    claimNumber($connection);

    test()->postJson("/api/connections/{$connection->id}/migration/verify-code", ['code' => '123456'])
        ->assertOk();

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active);
});

test('a connection with no WABA yet explains what to do first', function () {
    Http::fake();
    $connection = migrationConnectionWithWaba();
    $connection->forceFill(['credentials' => ['access_token' => 't']])->save();

    $response = claimNumber($connection);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Authorize with Meta first');
});

test('migration is refused on channels that are not WhatsApp Official', function () {
    Http::fake();
    $connection = migrationConnectionWithWaba();
    $connection->forceFill(['channel' => Channel::Telegram])->save();

    claimNumber($connection)->assertStatus(422);
});
