<?php

use App\Enums\Billing\BillingCycle;
use App\Exceptions\Billing\MissingBillingIdentityException;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A workspace with no CPF cannot be charged — and must be *told* that, not
 * handed a 500.
 *
 * The bug this guards against was a whole class of failure, not a typo:
 * `UserFacingException` carried a message and an http status and had no
 * render(), so throwing one out of a controller was an unhandled exception.
 * Laravel answered "Server Error", the careful sentence never left the process,
 * and the only trace was a log line — which is exactly how a customer ends up
 * unable to pay with nothing on screen to act on.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
});

function identityUser(bool $withDocument = false): User
{
    $user = User::factory()->create(['email' => 'id-'.uniqid().'@example.test']);
    $tenant = Tenant::create(array_filter([
        'user_id' => $user->id,
        'billing_name' => $withDocument ? 'Acme LTDA' : null,
        'billing_document_type' => $withDocument ? 'CNPJ' : null,
        'billing_document_number' => $withDocument ? '12345678000199' : null,
    ]));
    $user->forceFill(['tenant_id' => $tenant->id, 'whatsapp_verified_at' => now()])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach (['billing.view', 'billing.manage'] as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $user->assignRole($role);

    return $user->fresh();
}

test('subscribing without a document answers 422 with an actionable code, not 500', function () {
    $user = identityUser();
    Sanctum::actingAs($user);

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
    ]);

    $response = $this->postJson('/api/billing/subscribe', [
        'plan_id' => $plan->id,
        'method' => 'pix',
        'payer_email' => $user->email,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', MissingBillingIdentityException::CODE);

    // The sentence has to name the remedy — a code alone is for the UI, and a
    // customer reading the toast still has to know where to go.
    expect($response->json('message'))->toContain('CPF')->toContain('Empresa');

    // And nothing was sent: this is refused before the payment service is asked.
    Http::assertNothingSent();
});

test('a credit top-up without a document answers the same way', function () {
    // The path the customer actually hit: Billing → Saldo → Add credit never
    // goes through the plan checkout, so before this it was a bare 500.
    $user = identityUser();
    Sanctum::actingAs($user);

    $this->postJson('/api/credits/topup', ['amount_cents' => 5000])
        ->assertStatus(422)
        ->assertJsonPath('code', MissingBillingIdentityException::CODE);

    Http::assertNothingSent();
});

test('the document can be saved and read back masked', function () {
    $user = identityUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/billing/profile')->assertOk()->assertJsonPath('data.is_complete', false);

    $this->putJson('/api/billing/profile', [
        'billing_name' => 'Acme LTDA',
        'billing_document_type' => 'CNPJ',
        'billing_document_number' => '12.345.678/0001-99',
    ])->assertOk()->assertJsonPath('data.is_complete', true);

    // Punctuation stripped on the way in; only the last digits come back out.
    expect($user->tenant->fresh()->billing_document_number)->toBe('12345678000199');
    $this->getJson('/api/billing/profile')
        ->assertJsonPath('data.billing_document_hint', '•••0199');
});

test('a document of the wrong length is refused with a field error', function () {
    Sanctum::actingAs(identityUser());

    $this->putJson('/api/billing/profile', [
        'billing_name' => 'Acme LTDA',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '123',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['billing_document_number']]);
});

test('once the document is on file the charge goes through', function () {
    $user = identityUser(withDocument: true);
    Sanctum::actingAs($user);

    Http::fake(['*/payments' => Http::response(['data' => [
        'id' => 'pay_1',
        'status' => 'pending',
        'order_reference' => 'ref',
        'instructions' => ['type' => 'pix', 'qr_code' => 'QR'],
    ]])]);

    $this->postJson('/api/credits/topup', ['amount_cents' => 5000])->assertCreated();
});
