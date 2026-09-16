<?php

use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Market\MarketDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The tax number decides whether a country can pay at all.
 *
 * Before markets, this field accepted CPF and CNPJ and nothing else. From an
 * Indonesian workspace that did not read as a Brazilian assumption — it read as
 * a billing profile that refused to save, and therefore a plan that could never
 * be bought, with a sentence about digit counts as the only clue. These tests
 * exist because that failure is silent from the outside: every other part of
 * checkout works, and the wall is one `Rule::in`.
 */
uses(RefreshDatabase::class);

// Named for this file on purpose. Pest loads every test file into one process,
// so a second helper of the same name anywhere is a fatal redeclare — and
// borrowing a sibling file's helper breaks the moment this file runs alone.
function documentsMarket(string $code, string $currency, string $name): Market
{
    return Market::create([
        'code' => $code,
        'name' => $name,
        'currency' => $currency,
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);
}

function documentsOwner(string $marketCode): User
{
    $owner = User::factory()->create(['email' => 'doc-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $owner->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $owner->forceFill(['tenant_id' => $tenant->id, 'whatsapp_verified_at' => now()])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach (['billing.view', 'billing.manage'] as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $owner->assignRole($role);

    return $owner->fresh();
}

beforeEach(function () {
    // Saving an identity must never reach the payment service: it is refused or
    // stored here, and a stray call would mean we ask the acquirer to validate
    // what we already know is wrong.
    Http::preventStrayRequests();
});

it('accepts the document its own country uses', function () {
    documentsMarket('ID', 'IDR', 'Indonesia');
    $owner = documentsOwner('ID');
    Sanctum::actingAs($owner);

    $this->putJson('/api/billing/profile', [
        'billing_name' => 'PT Toko Aurora',
        'billing_document_type' => 'NPWP',
        // Written the way an Indonesian types it; the punctuation is stripped.
        'billing_document_number' => '09.123.456.7-890.123',
    ])->assertOk()->assertJsonPath('data.is_complete', true);

    expect($owner->tenant->fresh()->billing_document_number)->toBe('091234567890123');
});

it('refuses a document type belonging to another country', function () {
    documentsMarket('ID', 'IDR', 'Indonesia');
    Sanctum::actingAs(documentsOwner('ID'));

    // A CPF is a real document — just not one this workspace can hold. The
    // refusal has to name the field, or it reads as "your number is wrong".
    $this->putJson('/api/billing/profile', [
        'billing_name' => 'PT Toko Aurora',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678901',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['billing_document_type']]);
});

it('refuses a number of the wrong length for that document', function () {
    documentsMarket('ID', 'IDR', 'Indonesia');
    Sanctum::actingAs(documentsOwner('ID'));

    $this->putJson('/api/billing/profile', [
        'billing_name' => 'PT Toko Aurora',
        'billing_document_type' => 'NIK',
        'billing_document_number' => '12345',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['billing_document_number']]);
});

it('tells the workspace which documents its country accepts', function () {
    documentsMarket('ID', 'IDR', 'Indonesia');
    Sanctum::actingAs(documentsOwner('ID'));

    // The form renders from this, rather than shipping a country's documents in
    // the bundle — that is the whole reason it is on the response.
    $codes = collect($this->getJson('/api/billing/profile')->json('data.document_types'))
        ->pluck('code')
        ->all();

    expect($codes)->toEqualCanonicalizing(['NPWP', 'NIK']);
});

it('still offers CPF and CNPJ in Brazil', function () {
    Sanctum::actingAs(documentsOwner('BR'));

    $codes = collect($this->getJson('/api/billing/profile')->json('data.document_types'))
        ->pluck('code')
        ->all();

    expect($codes)->toEqualCanonicalizing(['CPF', 'CNPJ']);

    $this->putJson('/api/billing/profile', [
        'billing_name' => 'Acme LTDA',
        'billing_document_type' => 'CNPJ',
        'billing_document_number' => '12.345.678/0001-99',
    ])->assertOk()->assertJsonPath('data.is_complete', true);
});

it('falls back to a generic tax id in a market nobody has configured', function () {
    // Opening a market must not wait on somebody adding a row to config, and the
    // acquirer validates the number properly anyway.
    expect(MarketDocuments::codes('XX'))->toBe(['TAX_ID'])
        ->and(MarketDocuments::problem('XX', 'TAX_ID', '1234567'))->toBeNull()
        ->and(MarketDocuments::problem('XX', 'TAX_ID', '12'))->not->toBeNull();
});
