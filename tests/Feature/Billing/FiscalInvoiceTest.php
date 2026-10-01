<?php

use App\Enums\Billing\FiscalInvoiceStatus;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Jobs\IssueFiscalInvoice;
use App\Models\FiscalInvoice;
use App\Models\Invoice;
use App\Models\Market;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use App\Services\Billing\Fiscal\PlugnotasConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

/*
 * Notas fiscais Pingly issues, through Plugnotas, for invoices its Brazilian
 * customers pay. What these pin down: exactly one nota per paid invoice in
 * Brazil and none anywhere else, a lost answer adopted rather than issued
 * twice, and a refund that cancels the nota whatever stage it was at.
 */

uses(RefreshDatabase::class);

const FISCAL_CNPJ = '08187168000160';

beforeEach(function () {
    Bus::fake([IssueFiscalInvoice::class]);
    Http::preventStrayRequests();

    Setting::set(PlugnotasConfig::ENABLED, '1');
    Setting::set(PlugnotasConfig::SANDBOX, '1');
    Setting::set(PlugnotasConfig::API_KEY, 'plug-key');
    Setting::set(PlugnotasConfig::PRESTADOR_CNPJ, '08.187.168/0001-60');
    Setting::set(PlugnotasConfig::SERVICE_CODE, '1.03');
    Setting::set(PlugnotasConfig::CNAE, '6311-9/00');
    Setting::set(PlugnotasConfig::ISS_RATE, '2');
});

function fiscalWorkspace(string $market = 'BR'): Tenant
{
    if ($market !== 'BR' && ! Market::whereKey($market)->exists()) {
        Market::create([
            'code' => $market,
            'name' => 'Indonesia',
            'currency' => 'IDR',
            'default_locale' => 'id',
            'default_timezone' => 'Asia/Jakarta',
            'phone_country' => '62',
            'status' => 'active',
        ]);
    }

    $user = User::factory()->create(['email' => 'nf-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = $market;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $tenant->forceFill([
        'billing_name' => 'Loja Aurora Ltda',
        'billing_document_type' => 'CNPJ',
        'billing_document_number' => '11222333000181',
    ])->save();

    return $tenant->fresh();
}

function fiscalInvoice(Tenant $tenant, array $overrides = []): Invoice
{
    return Invoice::create(array_merge([
        'tenant_id' => $tenant->id,
        'purpose' => InvoicePurpose::CreditTopup,
        'status' => InvoiceStatus::Pending,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 14990,
        'currency' => $tenant->market_code === 'BR' ? 'BRL' : 'IDR',
    ], $overrides));
}

function fiscalPaid(Invoice $invoice): Invoice
{
    $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);

    return $invoice->fresh();
}

function plugnotasSummary(string $situacao, array $extra = []): array
{
    return [array_merge([
        'id' => '5ecbbaabbdbd4670e36b9999',
        'situacao' => $situacao,
        'mensagem' => $situacao === 'REJEITADO' ? '00017-O item da lista de serviços informado não consta no cadastro do prestador.' : 'RPS Autorizada com sucesso',
    ], $extra)];
}

it('queues one nota when a Brazilian invoice is paid', function () {
    $invoice = fiscalPaid(fiscalInvoice(fiscalWorkspace()));

    $row = FiscalInvoice::where('invoice_id', $invoice->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(FiscalInvoiceStatus::Pending)
        ->and($row->reference)->toBe('pingly-inv-'.$invoice->id)
        ->and($row->amount_cents)->toBe(14990);

    Bus::assertDispatched(IssueFiscalInvoice::class, fn ($job) => $job->fiscalInvoiceId === $row->id);

    // Saving it paid again (a redelivered webhook) does not queue a second one.
    $invoice->update(['paid_at' => now()->addMinute()]);
    app(FiscalInvoiceService::class)->queueFor($invoice);

    expect(FiscalInvoice::count())->toBe(1);
});

it('queues a nota for an invoice created already paid', function () {
    $tenant = fiscalWorkspace();
    fiscalInvoice($tenant, ['status' => InvoiceStatus::Paid, 'paid_at' => now()]);

    expect(FiscalInvoice::count())->toBe(1);
});

it('issues nothing outside Brazil, while switched off, or for an excluded purpose', function () {
    fiscalPaid(fiscalInvoice(fiscalWorkspace('ID')));
    expect(FiscalInvoice::count())->toBe(0);

    Setting::set(PlugnotasConfig::ENABLED, '0');
    fiscalPaid(fiscalInvoice(fiscalWorkspace()));
    expect(FiscalInvoice::count())->toBe(0);

    Setting::set(PlugnotasConfig::ENABLED, '1');
    Setting::set(PlugnotasConfig::PURPOSES, json_encode(['subscription']));
    fiscalPaid(fiscalInvoice(fiscalWorkspace()));
    expect(FiscalInvoice::count())->toBe(0);
});

it('sends the NFS-e to Plugnotas with the prestador, tomador and service', function () {
    $tenant = fiscalWorkspace();
    $tenant->update(['billing_address' => [
        'cep' => '87020-100', 'logradouro' => 'Rua Barão do Rio Branco', 'numero' => '1001',
        'bairro' => 'Centro', 'codigo_cidade' => '4115200', 'cidade' => 'Maringá', 'estado' => 'pr',
    ]]);

    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice($tenant))->id)->first();

    Http::fake([
        'api.sandbox.plugnotas.com.br/nfse' => Http::response([
            'documents' => [['idIntegracao' => $row->reference, 'id' => 'nota-1']],
            'message' => 'Nota(as) em processamento',
            'protocol' => 'proto-1',
        ]),
    ]);

    (new IssueFiscalInvoice($row->id))->handle(app(FiscalInvoiceService::class));

    $row->refresh();
    expect($row->status)->toBe(FiscalInvoiceStatus::Processing)
        ->and($row->provider_id)->toBe('nota-1')
        ->and($row->protocol)->toBe('proto-1');

    Http::assertSent(function (HttpRequest $request) use ($row) {
        $nota = $request->data()[0] ?? [];

        return $request->hasHeader('x-api-key', 'plug-key')
            && $nota['idIntegracao'] === $row->reference
            && $nota['prestador']['cpfCnpj'] === FISCAL_CNPJ
            && $nota['tomador']['cpfCnpj'] === '11222333000181'
            && $nota['tomador']['razaoSocial'] === 'Loja Aurora Ltda'
            && $nota['tomador']['endereco']['codigoCidade'] === '4115200'
            && $nota['tomador']['endereco']['estado'] === 'PR'
            && $nota['servico'][0]['codigo'] === '1.03'
            && $nota['servico'][0]['cnae'] === '6311900'
            && $nota['servico'][0]['iss']['aliquota'] === 2.0
            && $nota['servico'][0]['valor']['servico'] === 149.9
            && str_contains($nota['servico'][0]['discriminacao'], 'Recarga de saldo');
    });
});

it('leaves out an incomplete address rather than sending half of one', function () {
    $tenant = fiscalWorkspace();
    $tenant->update(['billing_address' => ['cep' => '87020100', 'logradouro' => 'Rua X']]);

    expect(app(FiscalInvoiceService::class)->address($tenant->fresh()))->toBeNull();
});

it('adopts a nota Plugnotas already holds instead of issuing a second', function () {
    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();

    Http::fake([
        'api.sandbox.plugnotas.com.br/nfse' => Http::response(['error' => ['message' => 'Já existe um(a) NFSe com os parâmetros informados']], 409),
        'api.sandbox.plugnotas.com.br/nfse/consultar/*' => Http::response(plugnotasSummary('CONCLUIDO', ['numeroNfse' => '202600000000123', 'codigoVerificacao' => 'abc123'])),
    ]);

    app(FiscalInvoiceService::class)->submit($row);

    $row->refresh();
    expect($row->status)->toBe(FiscalInvoiceStatus::Issued)
        ->and($row->number)->toBe('202600000000123')
        ->and($row->verification_code)->toBe('abc123')
        ->and($row->issued_at)->not->toBeNull();

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/nfse/consultar/'.$row->reference.'/'.FISCAL_CNPJ));
});

it('throws on a transport failure so the job retries', function () {
    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();

    Http::fake(['api.sandbox.plugnotas.com.br/nfse' => Http::response('bad gateway', 502)]);

    expect(fn () => app(FiscalInvoiceService::class)->submit($row))
        ->toThrow(\App\Services\Billing\Fiscal\PlugnotasException::class);

    expect($row->fresh()->status)->toBe(FiscalInvoiceStatus::Pending);
});

it('fails without calling Plugnotas when the configuration is incomplete', function () {
    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();
    Setting::set(PlugnotasConfig::SERVICE_CODE, null);

    Http::fake();
    app(FiscalInvoiceService::class)->submit($row);

    expect($row->fresh()->status)->toBe(FiscalInvoiceStatus::Failed)
        ->and($row->fresh()->message)->toContain('service_code');
    Http::assertNothingSent();
});

it('records a rejection and sends it again under a new reference', function () {
    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();
    $row->update(['status' => FiscalInvoiceStatus::Processing, 'provider_id' => 'nota-1']);

    Http::fake(['api.sandbox.plugnotas.com.br/nfse/consultar/*' => Http::response(plugnotasSummary('REJEITADO'))]);

    app(FiscalInvoiceService::class)->refresh($row);

    $row->refresh();
    expect($row->status)->toBe(FiscalInvoiceStatus::Rejected)
        ->and($row->message)->toContain('00017');

    app(FiscalInvoiceService::class)->retry($row);

    $row->refresh();
    expect($row->status)->toBe(FiscalInvoiceStatus::Pending)
        ->and($row->attempt)->toBe(2)
        ->and($row->reference)->toBe('pingly-inv-'.$row->invoice_id.'-2')
        ->and($row->provider_id)->toBeNull();
});

it('cancels an issued nota when the invoice is refunded', function () {
    $invoice = fiscalPaid(fiscalInvoice(fiscalWorkspace()));
    $row = FiscalInvoice::where('invoice_id', $invoice->id)->first();
    $row->update(['status' => FiscalInvoiceStatus::Issued, 'provider_id' => 'nota-1', 'number' => '55']);

    Http::fake([
        'api.sandbox.plugnotas.com.br/nfse/cancelar/nota-1' => Http::response(['message' => 'Cancelamento em processamento']),
        'api.sandbox.plugnotas.com.br/nfse/consultar/*' => Http::response(plugnotasSummary('CANCELADO')),
    ]);

    $invoice->update(['status' => InvoiceStatus::Refunded]);

    expect($row->fresh()->status)->toBe(FiscalInvoiceStatus::Cancelling);

    app(FiscalInvoiceService::class)->refresh($row->fresh());

    expect($row->fresh()->status)->toBe(FiscalInvoiceStatus::Cancelled)
        ->and($row->fresh()->cancelled_at)->not->toBeNull();
});

it('cancels as soon as a nota refunded mid-flight is authorized', function () {
    $invoice = fiscalPaid(fiscalInvoice(fiscalWorkspace()));
    $row = FiscalInvoice::where('invoice_id', $invoice->id)->first();
    $row->update(['status' => FiscalInvoiceStatus::Processing, 'provider_id' => 'nota-1']);

    $invoice->update(['status' => InvoiceStatus::Refunded]);
    expect($row->fresh()->metaValue('cancel_requested_at'))->not->toBeNull();

    Http::fake([
        'api.sandbox.plugnotas.com.br/nfse/consultar/*' => Http::response(plugnotasSummary('CONCLUIDO', ['numeroNfse' => '77'])),
        'api.sandbox.plugnotas.com.br/nfse/cancelar/nota-1' => Http::response(['message' => 'ok']),
    ]);

    app(FiscalInvoiceService::class)->refresh($row->fresh());

    expect($row->fresh()->status)->toBe(FiscalInvoiceStatus::Cancelling);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/nfse/cancelar/nota-1'));
});

it('marks a nota never sent as cancelled when the invoice is refunded first', function () {
    $invoice = fiscalPaid(fiscalInvoice(fiscalWorkspace()));
    $invoice->update(['status' => InvoiceStatus::Refunded]);

    expect(FiscalInvoice::where('invoice_id', $invoice->id)->first()->status)->toBe(FiscalInvoiceStatus::Cancelled);
});

it('accepts the webhook only with our token and reads the nota back', function () {
    Setting::set(PlugnotasConfig::WEBHOOK_TOKEN, 'tok-123');
    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();
    $row->update(['status' => FiscalInvoiceStatus::Processing, 'provider_id' => 'nota-1']);

    Http::fake(['api.sandbox.plugnotas.com.br/nfse/consultar/*' => Http::response(plugnotasSummary('CONCLUIDO', ['numeroNfse' => '99']))]);

    $this->postJson('/webhook/plugnotas', ['idIntegracao' => $row->reference, 'situacao' => 'CONCLUIDO'])
        ->assertStatus(401);
    expect($row->fresh()->status)->toBe(FiscalInvoiceStatus::Processing);

    // The body's own status is never trusted — only the read-back moves it.
    $this->postJson('/webhook/plugnotas', ['idIntegracao' => $row->reference, 'situacao' => 'CANCELADO'], [PlugnotasConfig::WEBHOOK_HEADER => 'tok-123'])
        ->assertOk();

    expect($row->fresh()->status)->toBe(FiscalInvoiceStatus::Issued)
        ->and($row->fresh()->number)->toBe('99');
});

it('resends pending notas whose job was lost and reads back the ones in flight', function () {
    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();
    FiscalInvoice::whereKey($row->id)->update(['updated_at' => now()->subHour()]);

    $other = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();
    $other->update(['status' => FiscalInvoiceStatus::Processing, 'provider_id' => 'nota-2']);

    Http::fake(['api.sandbox.plugnotas.com.br/nfse/consultar/*' => Http::response(plugnotasSummary('CONCLUIDO'))]);

    Bus::fake([IssueFiscalInvoice::class]);
    $this->artisan('fiscal-invoices:sync')->assertSuccessful();

    Bus::assertDispatched(IssueFiscalInvoice::class, fn ($job) => $job->fiscalInvoiceId === $row->id);
    expect($other->fresh()->status)->toBe(FiscalInvoiceStatus::Issued);
});

it('streams the nota PDF to the workspace that paid, and to nobody else', function () {
    Permission::findOrCreate('billing.view', 'web');

    $tenant = fiscalWorkspace();
    $invoice = fiscalPaid(fiscalInvoice($tenant));
    FiscalInvoice::where('invoice_id', $invoice->id)->update([
        'status' => FiscalInvoiceStatus::Issued->value, 'provider_id' => 'nota-1', 'number' => '123',
    ]);

    Http::fake(['api.sandbox.plugnotas.com.br/nfse/pdf/nota-1' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);

    $owner = User::where('tenant_id', $tenant->id)->first();
    $owner->givePermissionTo('billing.view');

    $this->actingAs($owner, 'sanctum')
        ->get("/api/billing/invoices/{$invoice->id}/nota-fiscal/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $stranger = User::where('tenant_id', fiscalWorkspace()->id)->first();
    $stranger->givePermissionTo('billing.view');

    $this->actingAs($stranger, 'sanctum')
        ->get("/api/billing/invoices/{$invoice->id}/nota-fiscal/pdf")
        ->assertNotFound();
});

it('lists the nota status beside the invoice, without Plugnotas wording', function () {
    Permission::findOrCreate('billing.view', 'web');

    $tenant = fiscalWorkspace();
    $invoice = fiscalPaid(fiscalInvoice($tenant));
    FiscalInvoice::where('invoice_id', $invoice->id)->update([
        'status' => FiscalInvoiceStatus::Rejected->value, 'message' => '00017-O item da lista…',
    ]);

    $owner = User::where('tenant_id', $tenant->id)->first();
    $owner->givePermissionTo('billing.view');

    $response = $this->actingAs($owner, 'sanctum')->getJson('/api/billing/invoices')->assertOk();

    expect($response->json('data.0.fiscal.status'))->toBe('rejected')
        ->and(json_encode($response->json()))->not->toContain('00017');
});

it('stores the billing address in Brazil and fills it from a CEP', function () {
    Permission::findOrCreate('billing.manage', 'web');
    Permission::findOrCreate('billing.view', 'web');

    $tenant = fiscalWorkspace();
    $owner = User::where('tenant_id', $tenant->id)->first();
    $owner->givePermissionTo(['billing.manage', 'billing.view']);

    $this->actingAs($owner, 'sanctum')->putJson('/api/billing/address', [
        'billing_address' => [
            'cep' => '87020-100', 'logradouro' => 'Rua Barão do Rio Branco', 'numero' => '1001',
            'bairro' => 'Centro', 'codigo_cidade' => '4115200', 'cidade' => 'Maringá', 'estado' => 'pr', 'complemento' => '',
        ],
    ])->assertOk()
        ->assertJsonPath('data.address_supported', true)
        ->assertJsonPath('data.billing_address.estado', 'PR')
        ->assertJsonPath('data.billing_address.codigo_cidade', '4115200');

    // Outside Brazil there is no nota, so there is no address to keep.
    $foreign = User::where('tenant_id', fiscalWorkspace('ID')->id)->first();
    $foreign->givePermissionTo('billing.manage');
    $this->actingAs($foreign, 'sanctum')->putJson('/api/billing/address', ['billing_address' => null])->assertNotFound();

    expect($tenant->fresh()->billing_address)->not->toHaveKey('complemento');

    Http::fake(['viacep.com.br/*' => Http::response([
        'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'bairro' => 'Sé',
        'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
    ])]);

    $this->actingAs($owner, 'sanctum')->getJson('/api/billing/cep/01001-000')
        ->assertOk()
        ->assertJsonPath('data.codigo_cidade', '3550308')
        ->assertJsonPath('data.estado', 'SP');
});

function fiscalAdmin(array $permissions): \App\Models\Admin
{
    $role = \Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $admin = \App\Models\Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

it('keeps issuing off when the operator turns it on with fields missing', function () {
    Setting::set(PlugnotasConfig::ENABLED, '0');
    Setting::set(PlugnotasConfig::ISS_RATE, null);

    $admin = fiscalAdmin(['bo.settings.manage']);

    $this->actingAs($admin)->putJson('/api/admin/fiscal/settings', ['enabled' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'plugnotas_incomplete');

    expect(PlugnotasConfig::enabled())->toBeFalse();

    $this->actingAs($admin)->putJson('/api/admin/fiscal/settings', ['enabled' => true, 'iss_rate' => 2.5])
        ->assertOk()
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.api_key_preview', '••••-key');
});

it('lets an operator send a rejected nota again, and only a rejected one', function () {
    $row = FiscalInvoice::where('invoice_id', fiscalPaid(fiscalInvoice(fiscalWorkspace()))->id)->first();
    $admin = fiscalAdmin(['bo.subscriptions.manage', 'bo.invoices.view']);

    $this->actingAs($admin)->postJson("/api/admin/fiscal-invoices/{$row->id}/retry")
        ->assertStatus(409);

    $row->update(['status' => FiscalInvoiceStatus::Rejected, 'message' => '00017-…']);

    $this->actingAs($admin)->getJson('/api/admin/fiscal-invoices?attention=1')
        ->assertOk()
        ->assertJsonPath('data.0.message', '00017-…');

    $this->actingAs($admin)->postJson("/api/admin/fiscal-invoices/{$row->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.attempt', 2);
});
