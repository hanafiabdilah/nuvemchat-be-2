<?php

use App\Enums\Market\MarketStatus;
use App\Http\Middleware\EnsurePlatformHost;
use App\Models\Market;
use App\Models\MarketDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

const COUNTRY_SITE = 'https://app.pingly.co.id';
const PLATFORM_SITE = 'https://chat.pingly.com.br';

/**
 * Path prefixes that only the platform domain answers. Anything added under
 * them later is covered by the structural test below without being listed.
 */
const PLATFORM_ONLY_PREFIXES = [
    'webhook',
    'widget-api',
    'api/admin',
    'oauth/instagram',
    'oauth/facebook',
    'oauth/tiktok',
    'instagram/deletion-status',
    'gallery/',
    'flow-payments/',
    'flow-invoices/',
    'storage/',
];

function registerCountryDomain(): void
{
    Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => MarketStatus::Draft,
    ]);

    MarketDomain::create(['market_code' => 'ID', 'domain' => 'app.pingly.co.id']);
}

test('every platform-only route carries the guard, including ones added later', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => collect(PLATFORM_ONLY_PREFIXES)
            ->contains(fn (string $prefix) => str_starts_with($route->uri(), $prefix)));

    // The list above must actually match routes, or this test proves nothing.
    expect($routes->count())->toBeGreaterThan(30);

    $unguarded = $routes
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($m) => $m === 'platform.only' || $m === EnsurePlatformHost::class))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});

test('a webhook reached through a country domain does not exist', function () {
    registerCountryDomain();

    $this->get(COUNTRY_SITE.'/webhook/facebook')->assertNotFound();

    // The same route on the platform domain reaches the controller.
    expect($this->get(PLATFORM_SITE.'/webhook/facebook')->status())->not->toBe(404);
});

test('the back office api does not exist on a country domain, before authentication is tried', function () {
    registerCountryDomain();

    $this->getJson(COUNTRY_SITE.'/api/admin/auth/me')->assertNotFound();
    $this->getJson(PLATFORM_SITE.'/api/admin/auth/me')->assertUnauthorized();
});

test('the widget api and signed links do not exist on a country domain', function () {
    registerCountryDomain();

    $this->getJson(COUNTRY_SITE.'/widget-api/config/any-app')->assertNotFound();
    $this->get(COUNTRY_SITE.'/flow-payments/pingly-fp-x/done')->assertNotFound();
    $this->get(COUNTRY_SITE.'/oauth/facebook/callback')->assertNotFound();
});

test('the dashboard api keeps working on a country domain', function () {
    registerCountryDomain();

    $this->getJson(COUNTRY_SITE.'/api/public/bootstrap')->assertOk();
    $this->postJson(COUNTRY_SITE.'/api/auth/login', [])->assertStatus(422);
});

test('a domain that belongs to no market is never blocked', function () {
    // Webhooks still registered at Meta on an old domain, the server's own IP,
    // internal self-requests: a deny-list leaves all of them alone.
    registerCountryDomain();

    expect($this->get('https://back-chat.adslogin.com.br/webhook/facebook')->status())->not->toBe(404);
});

test('the platform domain is never blocked, even when registered to a market by mistake', function () {
    MarketDomain::create(['market_code' => 'BR', 'domain' => 'chat.pingly.com.br']);

    config(['app.platform_url' => PLATFORM_SITE, 'app.url' => 'http://localhost']);
    expect($this->get(PLATFORM_SITE.'/webhook/facebook')->status())->not->toBe(404);

    // Without PLATFORM_URL, APP_URL names the platform host.
    config(['app.platform_url' => null, 'app.url' => PLATFORM_SITE]);
    expect($this->get(PLATFORM_SITE.'/webhook/facebook')->status())->not->toBe(404);
});

test('with no country domain registered, nothing is blocked', function () {
    expect(EnsurePlatformHost::isCountryDomain('app.pingly.co.id'))->toBeFalse();
    expect($this->get(COUNTRY_SITE.'/webhook/facebook')->status())->not->toBe(404);
});
