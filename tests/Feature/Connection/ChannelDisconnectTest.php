<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\ConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function disconnectTestTenant(): Tenant
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant;
}

function waOfficialConnection(Tenant $tenant, array $credentialOverrides = []): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA ' . uniqid(),
        'color' => '#22c55e',
        'status' => Status::Active,
        'credentials' => array_merge([
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
            'fb_user_id' => '333000333',
            'is_coexistence' => false,
        ], $credentialOverrides),
    ]);
}

function igConnection(Tenant $tenant): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Instagram,
        'name' => 'IG ' . uniqid(),
        'color' => '#e11d48',
        'status' => Status::Active,
        'credentials' => [
            'access_token' => 'ig-token',
            'page_id' => '444',
            'instagram_account_id' => '555',
            'user_id' => '444',
            'username' => 'shop',
        ],
    ]);
}

test('whatsapp official disconnect revokes remote access and clears credentials', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $connection = waOfficialConnection(disconnectTestTenant());

    (new ConnectionService())->disconnect($connection);

    expect($connection->fresh()->status)->toBe(Status::Inactive)
        ->and($connection->fresh()->credentials)->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '222000222/subscribed_apps'));
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '111000111/deregister'));
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '333000333/permissions'));
});

test('coexistence numbers are not deregistered on disconnect', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $connection = waOfficialConnection(disconnectTestTenant(), ['is_coexistence' => true]);

    (new ConnectionService())->disconnect($connection);

    expect($connection->fresh()->status)->toBe(Status::Inactive);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/deregister'));
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '222000222/subscribed_apps'));
});

test('waba webhook subscription is kept while an active sibling shares the waba', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $tenant = disconnectTestTenant();
    $connection = waOfficialConnection($tenant);
    waOfficialConnection($tenant, [
        'phone_number_id' => '111000999',
        'fb_user_id' => '333000999',
    ]);

    (new ConnectionService())->disconnect($connection);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'subscribed_apps'));
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '111000111/deregister'));
});

test('app permissions are kept while an active sibling shares the facebook user', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $tenant = disconnectTestTenant();
    $connection = waOfficialConnection($tenant);
    waOfficialConnection($tenant, [
        'phone_number_id' => '111000999',
        'business_account_id' => '222000999',
    ]);

    (new ConnectionService())->disconnect($connection);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '333000333/permissions'));
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '111000111/deregister'));
});

test('remote failures still deactivate the whatsapp connection locally', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190]], 401)]);

    $connection = waOfficialConnection(disconnectTestTenant());

    (new ConnectionService())->disconnect($connection);

    expect($connection->fresh()->status)->toBe(Status::Inactive)
        ->and($connection->fresh()->credentials)->toBeNull();
});

test('instagram disconnect deactivates locally without any graph call', function () {
    Http::fake();

    $connection = igConnection(disconnectTestTenant());

    (new ConnectionService())->disconnect($connection);

    expect($connection->fresh()->status)->toBe(Status::Inactive)
        ->and($connection->fresh()->credentials)->toBeNull();

    Http::assertNothingSent();
});

test('an active whatsapp official connection can be deleted', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $connection = waOfficialConnection(disconnectTestTenant());

    (new ConnectionService())->delete($connection);

    expect(Connection::find($connection->id))->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '111000111/deregister'));
});

test('an active instagram connection can be deleted', function () {
    Http::fake();

    $connection = igConnection(disconnectTestTenant());

    (new ConnectionService())->delete($connection);

    expect(Connection::find($connection->id))->toBeNull();
});
