<?php

use App\Models\Admin;
use App\Support\PlatformUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const PLATFORM_ROOT = 'https://chat.pingly.com.br';
const COUNTRY_ROOT = 'https://app.pingly.co.id';

/**
 * A route that reports the addresses the app would hand out while serving the
 * current request — the same calls channels and gateways make when they
 * register a webhook or sign a link.
 */
function platformUrlProbe(): void
{
    Route::get('/__platform-url-probe', fn () => response()->json([
        'webhook' => route('webhook.payments'),
        'signed' => URL::signedRoute('flow-payments.qr', ['reference' => 'pingly-fp-probe']),
    ]));
}

function pinPlatformUrl(?string $value): bool
{
    config(['app.platform_url' => $value]);

    return PlatformUrl::apply(app('url'));
}

function platformUrlAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.health.view', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function platformUrlCheck($response): ?array
{
    return collect($response->json('data.checks'))->firstWhere('key', 'platform:url');
}

test('addresses handed to outside systems carry the platform domain, whatever domain the request came in on', function () {
    // The owner connects Telegram from the Indonesian dashboard. The webhook
    // lives on in Telegram long after that domain might lapse.
    pinPlatformUrl(PLATFORM_ROOT);
    platformUrlProbe();

    $res = $this->getJson(COUNTRY_ROOT.'/__platform-url-probe')->assertOk();

    expect($res->json('webhook'))->toStartWith(PLATFORM_ROOT.'/');
    expect($res->json('signed'))->toStartWith(PLATFORM_ROOT.'/');
});

test('the platform scheme wins even when the request reached PHP over plain http', function () {
    // Providers refuse http:// callbacks; a proxy hop must not downgrade them.
    pinPlatformUrl(PLATFORM_ROOT);
    platformUrlProbe();

    $res = $this->getJson('http://app.pingly.co.id/__platform-url-probe')->assertOk();

    expect($res->json('webhook'))->toStartWith(PLATFORM_ROOT.'/');
});

test('a link signed while serving a country domain opens on the platform domain', function () {
    pinPlatformUrl(PLATFORM_ROOT);
    platformUrlProbe();

    $signed = $this->getJson(COUNTRY_ROOT.'/__platform-url-probe')->json('signed');

    // 404 = the signature passed and the controller looked for a payment that
    // doesn't exist. 403 would mean the link is dead on arrival.
    $this->get($signed)->assertNotFound();
});

test('the same signed link is rejected on another domain, which is why it must never be issued there', function () {
    pinPlatformUrl(PLATFORM_ROOT);
    platformUrlProbe();

    $signed = $this->getJson(COUNTRY_ROOT.'/__platform-url-probe')->json('signed');

    $this->get(str_replace(PLATFORM_ROOT, COUNTRY_ROOT, $signed))->assertForbidden();
});

test('without a platform url, addresses follow the request domain exactly as before', function () {
    expect(pinPlatformUrl(null))->toBeFalse();
    platformUrlProbe();

    $res = $this->getJson(COUNTRY_ROOT.'/__platform-url-probe')->assertOk();

    expect($res->json('webhook'))->toStartWith(COUNTRY_ROOT.'/');
});

test('a value that is not a bare scheme and host is ignored instead of breaking every url', function (string $value) {
    // "https://host/api" would prefix every route with a segment nothing
    // answers to; a scheme-less host would produce relative garbage.
    expect(pinPlatformUrl($value))->toBeFalse();
    expect(PlatformUrl::root())->toBeNull();
    platformUrlProbe();

    $res = $this->getJson(COUNTRY_ROOT.'/__platform-url-probe')->assertOk();

    expect($res->json('webhook'))->toStartWith(COUNTRY_ROOT.'/');
})->with([
    'no scheme' => 'chat.pingly.com.br',
    'with a path' => 'https://chat.pingly.com.br/api',
    'with a query' => 'https://chat.pingly.com.br?x=1',
    'ftp' => 'ftp://chat.pingly.com.br',
]);

test('a trailing slash and a port are accepted', function () {
    config(['app.platform_url' => 'https://chat.pingly.com.br:8443/']);

    expect(PlatformUrl::root())->toBe('https://chat.pingly.com.br:8443');
    expect(PlatformUrl::host())->toBe('chat.pingly.com.br');
});

test('health reads an unset platform url as ok, because one domain needs nothing', function () {
    config(['app.platform_url' => null]);

    $res = $this->actingAs(platformUrlAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();

    expect(platformUrlCheck($res))->toMatchArray(['status' => 'ok', 'value' => 'Not set']);
});

test('health warns when the platform url is unusable', function () {
    config(['app.platform_url' => 'chat.pingly.com.br']);

    $res = $this->actingAs(platformUrlAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();

    expect(platformUrlCheck($res))->toMatchArray(['status' => 'warn', 'value' => 'Invalid']);
});

test('health warns when APP_URL points somewhere else', function () {
    // Public file links still read APP_URL, so two hosts would be handed out.
    config(['app.platform_url' => PLATFORM_ROOT, 'app.url' => 'http://localhost']);

    $res = $this->actingAs(platformUrlAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();

    expect(platformUrlCheck($res))->toMatchArray(['status' => 'warn', 'value' => 'chat.pingly.com.br']);
});

test('health is ok when the platform url and APP_URL agree', function () {
    config(['app.platform_url' => PLATFORM_ROOT, 'app.url' => PLATFORM_ROOT]);

    $res = $this->actingAs(platformUrlAdmin(), 'sanctum')->getJson('/api/admin/health')->assertOk();

    expect(platformUrlCheck($res))->toMatchArray(['status' => 'ok', 'value' => 'chat.pingly.com.br']);
});
