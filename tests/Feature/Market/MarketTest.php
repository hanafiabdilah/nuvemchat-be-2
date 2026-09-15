<?php

use App\Enums\Market\MarketStatus;
use App\Models\Market;
use App\Models\MarketDomain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Market\MarketResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function indonesiaMarket(?string $domain = 'app.pingly.co.id'): Market
{
    $market = Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => MarketStatus::Draft,
    ]);

    if ($domain !== null) {
        $market->domains()->create(['domain' => $domain, 'is_primary' => true]);
    }

    return $market;
}

function marketTenant(): Tenant
{
    return Tenant::create(['user_id' => User::factory()->create()->id]);
}

function servingRequestOn(string $url): void
{
    app()->instance('request', Request::create($url));
}

function signUpOn(string $root, string $email): Tenant
{
    Role::findOrCreate('owner', 'web');

    test()->postJson($root.'/api/auth/register', [
        'name' => 'Loja',
        'email' => $email,
        'password' => 'supersecret',
        'password_confirmation' => 'supersecret',
        'whatsapp_number' => '+5511999999999',
    ])->assertCreated();

    return Tenant::findOrFail(User::where('email', $email)->value('tenant_id'));
}

test('brazil is the market every install starts with', function () {
    // Written by the migration, not a seeder: deploys only run migrate, and
    // every existing workspace points at this row.
    $br = Market::findOrFail('BR');

    expect($br->only(['name', 'currency', 'default_locale', 'default_timezone', 'phone_country']))->toBe([
        'name' => 'Brasil',
        'currency' => 'BRL',
        'default_locale' => 'pt_BR',
        'default_timezone' => 'America/Sao_Paulo',
        'phone_country' => '55',
    ]);
    expect($br->status)->toBe(MarketStatus::Active);
    expect(Market::default()->is($br))->toBeTrue();
});

test('a workspace created with nothing to decide belongs to the default market', function () {
    // Seeders, tests and every onboarding path that doesn't think about markets.
    $tenant = marketTenant();

    expect($tenant->market_code)->toBe('BR');
    expect($tenant->market->currency)->toBe('BRL');
});

test('a workspace created while serving a country domain belongs to that country', function () {
    indonesiaMarket();
    servingRequestOn('https://app.pingly.co.id/api/auth/register');

    expect(marketTenant()->market_code)->toBe('ID');
});

test('signing up on a country domain creates a workspace in that country', function () {
    indonesiaMarket();

    expect(signUpOn('https://app.pingly.co.id', 'dono@loja.co.id')->market_code)->toBe('ID');
});

test('signing up on the platform domain creates a workspace in the default market', function () {
    indonesiaMarket();

    expect(signUpOn('https://chat.pingly.com.br', 'dono@loja.com.br')->market_code)->toBe('BR');
});

test('the signup form cannot choose the market', function () {
    // The domain decides, not a field anyone can put in a request body.
    indonesiaMarket();

    $tenant = Tenant::create(['user_id' => User::factory()->create()->id, 'market_code' => 'ID']);

    expect($tenant->fresh()->market_code)->toBe('BR');
});

test('an explicit market set by our own code at creation is kept', function () {
    indonesiaMarket();

    $tenant = (new Tenant)->forceFill(['user_id' => User::factory()->create()->id, 'market_code' => 'ID']);
    $tenant->save();

    expect($tenant->fresh()->market_code)->toBe('ID');
});

test('a workspace never changes market', function () {
    // Its balance and invoices are amounts in the market's currency; moving it
    // would relabel the money, not convert it.
    indonesiaMarket();
    $tenant = marketTenant();

    expect(fn () => $tenant->forceFill(['market_code' => 'ID'])->save())->toThrow(LogicException::class);
    expect($tenant->fresh()->market_code)->toBe('BR');
});

test('the market is set and locked even when model events are faked or skipped', function () {
    // Event::fake() silences model events and saveQuietly() skips them; a
    // workspace must still be born with a country and keep it.
    Event::fake();
    indonesiaMarket('WWW.Pingly.ID');

    $tenant = marketTenant();

    expect($tenant->market_code)->toBe('BR');
    expect(MarketResolver::codeForHost('www.pingly.id'))->toBe('ID');
    expect(fn () => $tenant->forceFill(['market_code' => 'ID'])->saveQuietly())->toThrow(LogicException::class);
    expect(fn () => Market::findOrFail('BR')->forceFill(['currency' => 'USD'])->saveQuietly())->toThrow(LogicException::class);
});

test('ordinary updates to a workspace are untouched by the market lock', function () {
    $tenant = marketTenant();

    $tenant->update(['billing_name' => 'Loja Aurora LTDA']);

    expect($tenant->fresh()->billing_name)->toBe('Loja Aurora LTDA');
});

test('domains match regardless of case, port, scheme and a trailing dot', function () {
    indonesiaMarket();

    expect(MarketResolver::codeForHost('APP.Pingly.co.id:443'))->toBe('ID');
    expect(MarketResolver::codeForHost('app.pingly.co.id.'))->toBe('ID');
    expect(MarketResolver::codeForHost('https://app.pingly.co.id/login'))->toBe('ID');
    expect(MarketResolver::codeForHost('chat.pingly.com.br'))->toBe('BR');
    expect(MarketResolver::codeForHost(null))->toBe('BR');
});

test('a domain is stored in the same spelling it is matched in', function () {
    $domain = indonesiaMarket(null)->domains()->create(['domain' => 'WWW.Pingly.ID']);

    expect($domain->fresh()->domain)->toBe('www.pingly.id');
});

test('a domain added later is recognised without waiting for a cache to expire', function () {
    indonesiaMarket(null);

    expect(MarketResolver::codeForHost('app.pingly.id'))->toBe('BR');

    MarketDomain::create(['market_code' => 'ID', 'domain' => 'app.pingly.id']);

    expect(MarketResolver::codeForHost('app.pingly.id'))->toBe('ID');
});

test('a domain belongs to one market only', function () {
    indonesiaMarket();

    expect(fn () => MarketDomain::create(['market_code' => 'BR', 'domain' => 'app.pingly.co.id']))
        ->toThrow(QueryException::class);
});

test('a market with workspaces cannot be deleted', function () {
    marketTenant();

    expect(fn () => Market::findOrFail('BR')->delete())->toThrow(QueryException::class);
});

test('a market code never changes, and its currency is fixed once it has workspaces', function () {
    $indonesia = indonesiaMarket();
    $indonesia->update(['currency' => 'USD']);
    expect($indonesia->fresh()->currency)->toBe('USD');

    marketTenant();
    $brazil = Market::findOrFail('BR');

    expect(fn () => $brazil->update(['currency' => 'USD']))->toThrow(LogicException::class);
    expect(fn () => $brazil->update(['code' => 'XX']))->toThrow(LogicException::class);
    expect($brazil->fresh()->currency)->toBe('BRL');
});
