<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\FiscalInvoiceStatus;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FiscalInvoice;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use App\Services\Billing\Fiscal\PlugnotasClient;
use App\Services\Billing\Fiscal\PlugnotasConfig;
use App\Services\Billing\Fiscal\PlugnotasException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Back Office → Integrations → Nota fiscal (Plugnotas), plus the list of notas
 * the platform issued and the two operator actions on them.
 *
 * Plugnotas' own wording is shown verbatim here and only here: an operator is
 * the person who fixes the configuration, and "00017 – O item da lista de
 * serviços informado não consta no cadastro do prestador" is exactly what
 * they need to read.
 */
class AdminFiscalInvoiceController extends Controller
{
    // --- Settings ---------------------------------------------------------

    public function settings()
    {
        return response()->json(['data' => $this->settingsPayload()]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'sandbox' => ['sometimes', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'prestador_cnpj' => ['nullable', 'string', 'max:20'],
            'service_code' => ['nullable', 'string', 'max:20'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'cnae' => ['nullable', 'string', 'max:12'],
            'iss_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'iss_tax_type' => ['nullable', 'integer', 'between:0,8'],
            'iss_requirement' => ['nullable', 'integer', 'between:1,7'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'send_email' => ['sometimes', 'boolean'],
            'purposes' => ['sometimes', 'array'],
            'purposes.*' => [Rule::in(array_map(fn (InvoicePurpose $p) => $p->value, InvoicePurpose::cases()))],
        ]);

        if (array_key_exists('prestador_cnpj', $validated) && filled($validated['prestador_cnpj'])
            && strlen(preg_replace('/\D/', '', $validated['prestador_cnpj'])) !== 14) {
            return response()->json([
                'message' => 'The CNPJ must have 14 digits.',
                'errors' => ['prestador_cnpj' => ['The CNPJ must have 14 digits.']],
            ], 422);
        }

        // Turning it on with something missing would queue notas that can only
        // fail — refuse here, where the operator can still fix it.
        $flags = [
            PlugnotasConfig::ENABLED => 'enabled',
            PlugnotasConfig::SANDBOX => 'sandbox',
            PlugnotasConfig::SEND_EMAIL => 'send_email',
        ];

        foreach ($flags as $key => $field) {
            if (array_key_exists($field, $validated)) {
                Setting::set($key, $validated[$field] ? '1' : '0');
            }
        }

        // A key is replaced only when one is typed: the form never receives it
        // back, so an empty field means "keep", not "erase".
        if (filled($validated['api_key'] ?? null)) {
            Setting::set(PlugnotasConfig::API_KEY, trim($validated['api_key']));
        }

        $texts = [
            PlugnotasConfig::PRESTADOR_CNPJ => 'prestador_cnpj',
            PlugnotasConfig::SERVICE_CODE => 'service_code',
            PlugnotasConfig::TAX_CODE => 'tax_code',
            PlugnotasConfig::CNAE => 'cnae',
            PlugnotasConfig::ISS_RATE => 'iss_rate',
            PlugnotasConfig::ISS_TAX_TYPE => 'iss_tax_type',
            PlugnotasConfig::ISS_REQUIREMENT => 'iss_requirement',
            PlugnotasConfig::NOTES => 'notes',
        ];

        foreach ($texts as $key => $field) {
            if (array_key_exists($field, $validated)) {
                $value = $validated[$field];
                Setting::set($key, $value === null || $value === '' ? null : trim((string) $value));
            }
        }

        if (array_key_exists('purposes', $validated)) {
            Setting::set(PlugnotasConfig::PURPOSES, json_encode(array_values(array_unique($validated['purposes']))));
        }

        if (PlugnotasConfig::enabled() && PlugnotasConfig::missing() !== []) {
            Setting::set(PlugnotasConfig::ENABLED, '0');

            return response()->json([
                'message' => 'Saved, but issuing stays off until these are filled: '.implode(', ', PlugnotasConfig::missing()).'.',
                'code' => 'plugnotas_incomplete',
                'data' => $this->settingsPayload(),
            ], 422);
        }

        AuditLog::record('fiscal.settings_updated', 'Updated the nota fiscal (Plugnotas) settings', [
            'fields' => array_keys(array_diff_key($validated, ['api_key' => true])),
            'api_key_changed' => filled($validated['api_key'] ?? null),
        ]);

        return response()->json(['data' => $this->settingsPayload()]);
    }

    /** Proves the key and the CNPJ together: reads the company registered under it. */
    public function test(PlugnotasClient $client)
    {
        $cnpj = PlugnotasConfig::prestadorCnpj();

        if (PlugnotasConfig::apiKey() === null || $cnpj === null) {
            return response()->json(['data' => ['ok' => false, 'message' => 'Fill in the API key and the CNPJ first.']], 422);
        }

        try {
            $company = $client->company($cnpj);
        } catch (PlugnotasException $e) {
            return response()->json(['data' => [
                'ok' => false,
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]], 422);
        }

        return response()->json(['data' => [
            'ok' => true,
            'environment' => PlugnotasConfig::sandbox() ? 'sandbox' : 'production',
            'company' => [
                'cnpj' => $company['cpfCnpj'] ?? $cnpj,
                'name' => $company['razaoSocial'] ?? $company['nomeFantasia'] ?? null,
                'municipal_registration' => $company['inscricaoMunicipal'] ?? null,
                'city' => $company['endereco']['descricaoCidade'] ?? null,
                'state' => $company['endereco']['estado'] ?? null,
                'nfse_enabled' => (bool) data_get($company, 'nfse.ativo', true),
            ],
        ]]);
    }

    public function registerWebhook(PlugnotasClient $client)
    {
        $cnpj = PlugnotasConfig::prestadorCnpj();

        if (PlugnotasConfig::apiKey() === null || $cnpj === null) {
            return response()->json(['message' => 'Fill in the API key and the CNPJ first.'], 422);
        }

        try {
            $client->registerWebhook($cnpj, PlugnotasConfig::webhookUrl(), PlugnotasConfig::ensureWebhookToken());
        } catch (PlugnotasException $e) {
            return response()->json(['message' => $e->getMessage(), 'status' => $e->status], 422);
        }

        Setting::set('plugnotas.webhook_registered_at', now()->toIso8601String());

        AuditLog::record('fiscal.webhook_registered', 'Registered the Plugnotas webhook', [
            'url' => PlugnotasConfig::webhookUrl(),
        ]);

        return response()->json(['data' => $this->settingsPayload()]);
    }

    // --- Notas ------------------------------------------------------------

    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $rows = FiscalInvoice::query()
            ->with(['tenant.user', 'invoice'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', (array) $request->query('status')))
            ->when($request->boolean('attention'), fn ($q) => $q->whereIn('status', [
                FiscalInvoiceStatus::Rejected->value,
                FiscalInvoiceStatus::Failed->value,
            ]))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->query('search');
                $q->where(fn ($w) => $w->where('number', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhereHas('tenant.user', fn ($u) => $u
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")));
            })
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->query('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->query('to'))->endOfDay()))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $rows->getCollection()->transform(fn (FiscalInvoice $row) => $this->row($row));

        $counts = FiscalInvoice::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'counts' => $counts,
            ],
        ]);
    }

    public function retry(FiscalInvoice $fiscalInvoice, FiscalInvoiceService $notas)
    {
        if (! $fiscalInvoice->status->needsOperator()) {
            return response()->json([
                'message' => 'Only a rejected or failed nota can be sent again.',
                'code' => 'fiscal_not_retryable',
            ], 409);
        }

        $notas->retry($fiscalInvoice);

        AuditLog::record('fiscal.retried', 'Sent a nota fiscal again', [
            'fiscal_invoice_id' => $fiscalInvoice->id,
            'invoice_id' => $fiscalInvoice->invoice_id,
            'tenant_id' => $fiscalInvoice->tenant_id,
        ]);

        return response()->json(['data' => $this->row($fiscalInvoice->fresh(['tenant.user', 'invoice']))]);
    }

    public function refresh(FiscalInvoice $fiscalInvoice, FiscalInvoiceService $notas)
    {
        $notas->refresh($fiscalInvoice);

        return response()->json(['data' => $this->row($fiscalInvoice->fresh(['tenant.user', 'invoice']))]);
    }

    /**
     * Issue the nota for a paid invoice that never got one — paid before the
     * integration was switched on, or excluded by a purpose filter since lifted.
     */
    public function issueForInvoice(Invoice $invoice, FiscalInvoiceService $notas)
    {
        if ($invoice->status !== InvoiceStatus::Paid || ! $notas->eligible($invoice)) {
            return response()->json([
                'message' => 'This invoice does not qualify for a nota (it must be paid, in BRL, from a workspace in Brazil, of a purpose that is issued).',
                'code' => 'fiscal_not_eligible',
            ], 422);
        }

        if (! PlugnotasConfig::enabled()) {
            return response()->json(['message' => 'Issuing is switched off.', 'code' => 'fiscal_disabled'], 422);
        }

        $row = $notas->queueFor($invoice);

        AuditLog::record('fiscal.issued_manually', 'Queued a nota fiscal by hand', [
            'invoice_id' => $invoice->id,
            'tenant_id' => $invoice->tenant_id,
        ]);

        return response()->json(['data' => $row ? $this->row($row->fresh(['tenant.user', 'invoice'])) : null]);
    }

    // --- Shapes -----------------------------------------------------------

    private function row(FiscalInvoice $row): array
    {
        return [
            'id' => $row->id,
            'invoice_id' => $row->invoice_id,
            'tenant_id' => $row->tenant_id,
            'tenant_name' => $row->tenant?->billing_name ?: $row->tenant?->user?->name,
            'tenant_email' => $row->tenant?->user?->email,
            'status' => $row->status->value,
            'attempt' => $row->attempt,
            'reference' => $row->reference,
            'provider_id' => $row->provider_id,
            'number' => $row->number,
            'verification_code' => $row->verification_code,
            'amount_cents' => $row->amount_cents,
            'purpose' => $row->invoice?->purpose?->value,
            'description' => $row->description,
            'message' => $row->message,
            'cancel_refused' => $row->metaValue('cancel_refused'),
            'cancel_error' => $row->metaValue('cancel_error'),
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'issued_at' => $row->issued_at?->toIso8601String(),
            'cancelled_at' => $row->cancelled_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function settingsPayload(): array
    {
        $key = PlugnotasConfig::apiKey();

        return [
            'enabled' => PlugnotasConfig::enabled(),
            'ready' => PlugnotasConfig::ready(),
            'missing' => PlugnotasConfig::missing(),
            'sandbox' => PlugnotasConfig::sandbox(),
            'api_key_set' => $key !== null,
            'api_key_preview' => $key ? '••••'.substr($key, -4) : null,
            'prestador_cnpj' => PlugnotasConfig::prestadorCnpj(),
            'service_code' => PlugnotasConfig::serviceCode(),
            'tax_code' => PlugnotasConfig::taxCode(),
            'cnae' => PlugnotasConfig::cnae(),
            'iss_rate' => PlugnotasConfig::issRate(),
            'iss_tax_type' => PlugnotasConfig::issTaxType(),
            'iss_requirement' => PlugnotasConfig::issRequirement(),
            'notes' => PlugnotasConfig::notes(),
            'send_email' => PlugnotasConfig::sendEmail(),
            'purposes' => PlugnotasConfig::purposes(),
            'all_purposes' => array_map(fn (InvoicePurpose $p) => $p->value, InvoicePurpose::cases()),
            'webhook_url' => PlugnotasConfig::webhookUrl(),
            'webhook_registered_at' => Setting::get('plugnotas.webhook_registered_at'),
        ];
    }
}
