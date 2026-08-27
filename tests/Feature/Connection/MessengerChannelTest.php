<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Channels\MessengerChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function messengerTestTenant(): Tenant
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant;
}

function pendingMessengerConnection(Tenant $tenant): Connection
{
    // State the OAuth callback leaves behind when the user manages 2+ pages.
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Messenger,
        'name' => 'Messenger ' . uniqid(),
        'color' => '#0084ff',
        'status' => Status::Pending,
        'credentials' => [
            'user_access_token' => 'long-lived-user-token',
            'fb_user_id' => '777000777',
            'pending_pages' => [
                ['id' => '111', 'name' => 'Página A'],
                ['id' => '222', 'name' => 'Página B'],
            ],
        ],
    ]);
}

test('connect via page picker fetches the page token, activates and subscribes the webhook', function () {
    Http::fake([
        'graph.facebook.com/v25.0/111?*' => Http::response(['access_token' => 'page-token-111']),
        'graph.facebook.com/v25.0/me*' => Http::response(['id' => '111', 'name' => 'Página A']),
        'graph.facebook.com/v25.0/111/subscribed_apps' => Http::response(['success' => true]),
    ]);

    $connection = pendingMessengerConnection(messengerTestTenant());

    (new MessengerChannel)->connect($connection, ['page_id' => '111']);

    $connection->refresh();

    expect($connection->status)->toBe(Status::Active)
        ->and($connection->credentials['page_id'])->toBe('111')
        ->and($connection->credentials['page_name'])->toBe('Página A')
        ->and($connection->credentials['access_token'])->toBe('page-token-111')
        ->and($connection->credentials['fb_user_id'])->toBe('777000777');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/111/subscribed_apps'));
});

test('connect uses the page token captured during login instead of re-fetching it', function () {
    // Graph refuses GET /{page_id}?fields=access_token whenever the app cannot
    // read the Page node — the normal case before Advanced Access. The token
    // the login already handed us is the only one that works, so the picker
    // must never go back and ask for it again.
    Http::fake([
        'graph.facebook.com/v25.0/111?*' => Http::response([
            'error' => ['message' => '(#100) Object does not exist, cannot be loaded due to missing permission', 'code' => 100],
        ], 400),
        'graph.facebook.com/v25.0/me*' => Http::response(['id' => '111', 'name' => 'Página A']),
        'graph.facebook.com/v25.0/111/subscribed_apps' => Http::response(['success' => true]),
    ]);

    $connection = pendingMessengerConnection(messengerTestTenant());
    $credentials = $connection->credentials;
    $credentials['pending_pages'][0]['access_token'] = 'page-token-from-login';
    $connection->forceFill(['credentials' => $credentials])->save();

    (new MessengerChannel)->connect($connection, ['page_id' => '111']);

    $connection->refresh();

    expect($connection->status)->toBe(Status::Active)
        ->and($connection->credentials['access_token'])->toBe('page-token-from-login');

    // The unpicked page's token goes away with the rest of pending_pages.
    expect($connection->credentials)->not->toHaveKey('pending_pages');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com/v25.0/111?'));
});

test('connect rejects a page that was not authorized during login', function () {
    $connection = pendingMessengerConnection(messengerTestTenant());

    expect(fn () => (new MessengerChannel)->connect($connection, ['page_id' => '999']))
        ->toThrow(ValidationException::class);
});

test('the same page cannot be connected twice', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['id' => '111', 'name' => 'Página A', 'access_token' => 'page-token-111', 'success' => true]),
    ]);

    $tenant = messengerTestTenant();

    Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Messenger,
        'name' => 'Já conectada',
        'color' => '#0084ff',
        'status' => Status::Active,
        'credentials' => ['page_id' => '111', 'access_token' => 'x'],
    ]);

    $connection = pendingMessengerConnection($tenant);

    expect(fn () => (new MessengerChannel)->connect($connection, ['page_id' => '111']))
        ->toThrow(ValidationException::class);
});

test('disconnect unsubscribes the page and clears credentials locally', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $connection = Connection::create([
        'tenant_id' => messengerTestTenant()->id,
        'channel' => Channel::Messenger,
        'name' => 'Messenger',
        'color' => '#0084ff',
        'status' => Status::Active,
        'credentials' => ['page_id' => '111', 'access_token' => 'page-token-111'],
    ]);

    (new MessengerChannel)->disconnect($connection);

    $connection->refresh();

    expect($connection->status)->toBe(Status::Inactive)
        ->and($connection->credentials)->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/111/subscribed_apps'));
});

test('remote failures still deactivate the messenger connection locally', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    $connection = Connection::create([
        'tenant_id' => messengerTestTenant()->id,
        'channel' => Channel::Messenger,
        'name' => 'Messenger',
        'color' => '#0084ff',
        'status' => Status::Active,
        'credentials' => ['page_id' => '111', 'access_token' => 'page-token-111'],
    ]);

    (new MessengerChannel)->disconnect($connection);

    expect($connection->fresh()->status)->toBe(Status::Inactive);
});
