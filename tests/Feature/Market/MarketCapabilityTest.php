<?php

use App\Enums\Market\MarketCapability;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gallery\GalleryRentalService;
use App\Services\Market\MarketCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * What a country may sell, and what it may connect.
 *
 * Plans already carry a country dimension through their prices, so what these
 * cover is everything that has none: products bought outside a plan, and the
 * outside accounts a workspace connects. The failure being guarded against is
 * quiet — an Indonesian workspace paying for a Brazilian SMS number that can
 * never receive its code, or filling in a Pix gateway form that will never
 * verify — so each gate is asserted at the door *and* at the service, because
 * jobs, commands and the scheduler never pass a route.
 */
uses(RefreshDatabase::class);

// Named for this file: Pest loads every test file into one process, so a helper
// sharing a name with a sibling's is a fatal redeclare.
function capabilityMarket(string $code = 'ID'): Market
{
    return Market::create([
        'code' => $code,
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);
}

/** @param  list<string>  $permissions */
function capabilityOwner(string $marketCode, array $permissions = []): User
{
    $owner = User::factory()->create(['email' => 'cap-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $owner->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $owner->forceFill(['tenant_id' => $tenant->id, 'whatsapp_verified_at' => now()])->save();

    if ($permissions !== []) {
        $role = Role::findOrCreate('owner', 'web');

        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        $owner->assignRole($role);
    }

    return $owner->fresh();
}

beforeEach(function () {
    // Nothing here may reach a supplier: every refusal has to happen before we
    // ask anyone to deliver something that cannot be delivered.
    Http::preventStrayRequests();
});

it('answers from the supplier country when nobody has decided', function () {
    capabilityMarket();

    expect(MarketCapabilities::allows('BR', MarketCapability::VirtualNumbers->value))->toBeTrue()
        ->and(MarketCapabilities::allows('ID', MarketCapability::VirtualNumbers->value))->toBeFalse()
        // Our own disk has no country.
        ->and(MarketCapabilities::allows('ID', MarketCapability::GalleryStorage->value))->toBeTrue()
        // Pix is a Brazilian rail; Stripe is not.
        ->and(MarketCapabilities::allows('ID', 'integration:openpix'))->toBeFalse()
        ->and(MarketCapabilities::allows('ID', 'integration:stripe'))->toBeTrue();
});

it('lets an admin overrule the default in either direction', function () {
    $market = capabilityMarket();

    $market->capabilities = ['virtual_numbers' => true];
    $market->save();

    expect(MarketCapabilities::allows('ID', 'virtual_numbers'))->toBeTrue();

    // And the other way: a country can stop selling something its supplier is in.
    $br = Market::findOrFail('BR');
    $br->capabilities = ['virtual_numbers' => false];
    $br->save();

    expect(MarketCapabilities::allows('BR', 'virtual_numbers'))->toBeFalse();
});

it('keeps unknown keys out of what is stored', function () {
    expect(MarketCapabilities::sanitize([
        'virtual_numbers' => 1,
        'integration:stripe' => false,
        'something_invented' => true,
    ]))->toBe([
        'virtual_numbers' => true,
        'integration:stripe' => false,
    ]);
});

it('refuses to sell a virtual number where the stock does not exist', function () {
    capabilityMarket();
    Sanctum::actingAs(capabilityOwner('ID', ['numbers.view', 'numbers.manage']));

    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'uber'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_available_in_market');

    // The catalog goes with it: a price list for something unbuyable is an
    // invitation to a 403.
    $this->getJson('/api/numbers/catalog')
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_available_in_market');
});

it('still lets a workspace read and cancel a number it already rents', function () {
    // Turning a capability off stops new sales. It must never strand somebody
    // with an asset that keeps billing and cannot be cancelled — so these two
    // routes carry no capability gate, and that is a guarantee worth a test
    // rather than a comment.
    foreach (['numbers.index', 'numbers.cancel', 'apiway.subscriptions.renew', 'apiway.subscriptions.cancel'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("route {$name} is missing")
            ->and(collect($route->gatherMiddleware())->filter(fn ($m) => str_starts_with((string) $m, 'capability:'))->all())
            ->toBe([], "route {$name} must stay reachable after a capability is switched off");
    }
});

it('refuses an API Way instance where ProxyBR does not provision', function () {
    capabilityMarket();
    Sanctum::actingAs(capabilityOwner('ID', ['billing.manage']));

    $this->postJson('/api/apiway/instances', ['mode' => 'unit', 'quantity' => 1])
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_available_in_market');
});

it('gates growing a storage rental, never shrinking one', function () {
    capabilityMarket();
    $market = Market::findOrFail('ID');
    $market->capabilities = ['gallery_storage' => false];
    $market->save();

    $tenant = capabilityOwner('ID')->tenant;
    $rentals = app(GalleryRentalService::class);

    expect(fn () => $rentals->setAmount($tenant, 5))->toThrow(ValidationException::class);

    // Asking for nothing is a cancellation, and it stays possible.
    expect($rentals->setAmount($tenant, 0))->toBeNull();
});

it('offers only the integrations the country can connect', function () {
    capabilityMarket();
    Sanctum::actingAs(capabilityOwner('ID', ['integrations.view', 'integrations.manage']));

    $response = $this->getJson('/api/integrations')->assertOk();

    $providers = collect($response->json('catalog'))->pluck('provider')->all();

    expect($providers)->toContain('stripe', 'meta_pixel', 'google_analytics')
        ->and($providers)->not->toContain('openpix', 'mercadopago', 'asaas', 'spedy');

    // A category whose last provider is gone disappears with it rather than
    // standing there empty.
    expect(collect($response->json('categories'))->pluck('key')->all())->not->toContain('invoice');
});

it('refuses to connect a provider that country cannot use', function () {
    capabilityMarket();
    Sanctum::actingAs(capabilityOwner('ID', ['integrations.view', 'integrations.manage']));

    // The catalog already hides it; this is the same answer for a request typed
    // by hand or left in an old tab.
    $this->postJson('/api/integrations', [
        'provider' => 'spedy',
        'name' => 'Notas',
        'credentials' => ['api_key' => str_repeat('k', 32)],
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['provider']]);
});

it('keeps Brazil selling everything it sold before', function () {
    Sanctum::actingAs(capabilityOwner('BR', ['integrations.view']));

    $providers = collect($this->getJson('/api/integrations')->assertOk()->json('catalog'))
        ->pluck('provider')
        ->all();

    expect($providers)->toContain('openpix', 'mercadopago', 'asaas', 'stripe', 'spedy')
        ->and(MarketCapabilities::forMarket('BR'))->not->toContain(false);
});
