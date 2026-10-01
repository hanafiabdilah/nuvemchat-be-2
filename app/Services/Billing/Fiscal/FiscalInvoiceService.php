<?php

namespace App\Services\Billing\Fiscal;

use App\Enums\Billing\FiscalInvoiceStatus;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Jobs\IssueFiscalInvoice;
use App\Models\FiscalInvoice;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Notas fiscais for the invoices Brazilian workspaces pay Pingly.
 *
 * The rule in one sentence: every paid invoice of a workspace in the BR market,
 * in reais, gets exactly one nota — and a refunded one gets it cancelled.
 *
 * Exactly one is the part everything here is built around. An invoice turns
 * paid on seven different paths (Pix webhook, card charge, Mercado Pago
 * preapproval, renewal, top-up…), so the trigger is an observer on the model
 * rather than any of them; the row is unique per invoice, so the observer, the
 * job, the sweep and an operator's retry all converge on it; and the reference
 * sent to Plugnotas (`idIntegracao`) is refused a second time there, so even a
 * submission whose answer was lost is adopted rather than issued twice.
 *
 * Nothing here may fail a payment. The nota is a consequence of the money
 * arriving, never a condition for it — every entry point swallows and logs.
 */
class FiscalInvoiceService
{
    /** Notas are issued in reais for workspaces in Brazil, and nowhere else. */
    public const MARKET = 'BR';

    public const CURRENCY = 'BRL';

    /** A pending row this old lost its job (worker died, deploy) — the sweep resends it. */
    public const STALE_PENDING_MINUTES = 10;

    /** How often the sweep asks about a nota still with the prefeitura. */
    public const RECHECK_MINUTES = 3;

    /**
     * After this long without an answer the nota is still followed, but it is
     * flagged for an operator: a prefeitura that has not answered in a day is
     * an outage on their side, not a queue.
     */
    public const LATE_HOURS = 24;

    public function __construct(protected PlugnotasClient $client) {}

    /**
     * Should this invoice get a nota at all? Checked on the invoice as it is
     * now, so a refund racing the payment does not produce one.
     */
    public function eligible(Invoice $invoice): bool
    {
        if ($invoice->status !== InvoiceStatus::Paid || $invoice->amount_cents <= 0) {
            return false;
        }

        if (strtoupper((string) $invoice->currency) !== self::CURRENCY) {
            return false;
        }

        $tenant = $invoice->tenant;

        if (! $tenant || $tenant->market_code !== self::MARKET) {
            return false;
        }

        return PlugnotasConfig::issuesFor($invoice->purpose);
    }

    /**
     * Record that this invoice needs a nota and queue it. Idempotent: the
     * unique index on invoice_id is the lock.
     */
    public function queueFor(Invoice $invoice): ?FiscalInvoice
    {
        if (! PlugnotasConfig::enabled() || ! $this->eligible($invoice)) {
            return null;
        }

        $existing = FiscalInvoice::query()->where('invoice_id', $invoice->id)->first();

        if ($existing) {
            return $existing;
        }

        try {
            $row = FiscalInvoice::create([
                'invoice_id' => $invoice->id,
                'tenant_id' => $invoice->tenant_id,
                'provider' => 'plugnotas',
                'status' => FiscalInvoiceStatus::Pending,
                'attempt' => 1,
                'reference' => $this->referenceFor($invoice, 1),
                'amount_cents' => $invoice->amount_cents,
            ]);
        } catch (QueryException) {
            // Another path got there first — that is the point of the index.
            return FiscalInvoice::query()->where('invoice_id', $invoice->id)->first();
        }

        IssueFiscalInvoice::dispatch($row->id);

        return $row;
    }

    /**
     * Send a pending nota to Plugnotas. Safe to run twice: a resubmission of
     * the same reference is refused there (409) and the existing nota adopted.
     */
    public function submit(FiscalInvoice $row): FiscalInvoice
    {
        // No lock: two workers sending the same pending row is harmless, the
        // second is refused by Plugnotas (409) and adopts the first.
        $row = $row->fresh() ?? $row;

        if ($row->status !== FiscalInvoiceStatus::Pending) {
            return $row;
        }

        $invoice = $row->invoice()->with('tenant.user')->first();

        if (! $invoice) {
            return $row;
        }

        // A refund that landed before the nota was ever sent: nothing to issue,
        // nothing to cancel.
        if ($invoice->status === InvoiceStatus::Refunded) {
            $row->update(['status' => FiscalInvoiceStatus::Cancelled, 'cancelled_at' => now(), 'message' => 'Invoice refunded before the nota was issued.']);

            return $row;
        }

        $missing = PlugnotasConfig::missing();

        if ($missing !== []) {
            return $this->fail($row, 'Plugnotas is not configured: missing '.implode(', ', $missing).'.');
        }

        $problem = $this->tomadorProblem($invoice->tenant);

        if ($problem !== null) {
            return $this->fail($row, $problem);
        }

        $description = $this->describe($invoice);
        $payload = $this->payload($row, $invoice, $description);

        try {
            $result = $this->client->issue($payload);
        } catch (PlugnotasException $e) {
            if ($e->isDuplicate()) {
                // Already there under this reference — a previous attempt whose
                // answer was lost. Adopt it and let the read-back decide.
                $row->update([
                    'status' => FiscalInvoiceStatus::Processing,
                    'description' => $description,
                    'submitted_at' => $row->submitted_at ?? now(),
                    'message' => $e->getMessage(),
                ]);

                return $this->refresh($row->fresh());
            }

            if ($e->isTransport()) {
                // Unknown whether it arrived; the job retries, and a retry of
                // something that did arrive comes back as the 409 above.
                throw $e;
            }

            return $this->fail($row, $e->getMessage());
        }

        $row->update([
            'status' => FiscalInvoiceStatus::Processing,
            'provider_id' => $result['id'],
            'protocol' => $result['protocol'],
            'description' => $description,
            'submitted_at' => now(),
            'message' => $result['message'],
        ]);

        Log::info('Nota fiscal submitted', [
            'fiscal_invoice_id' => $row->id,
            'invoice_id' => $invoice->id,
            'tenant_id' => $invoice->tenant_id,
            'reference' => $row->reference,
            'provider_id' => $result['id'],
        ]);

        return $row;
    }

    /** Read the nota back from Plugnotas and apply what it says. */
    public function refresh(FiscalInvoice $row): FiscalInvoice
    {
        $cnpj = PlugnotasConfig::prestadorCnpj();

        if ($cnpj === null || ! in_array($row->status, [FiscalInvoiceStatus::Processing, FiscalInvoiceStatus::Cancelling], true)) {
            return $row;
        }

        try {
            $summary = $this->client->summary($row->reference, $cnpj);
        } catch (PlugnotasException $e) {
            $row->update(['checked_at' => now()]);

            Log::warning('Nota fiscal read-back failed', [
                'fiscal_invoice_id' => $row->id,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return $row;
        }

        if ($summary === null) {
            $row->update(['checked_at' => now()]);

            return $row;
        }

        return $this->apply($row, $summary);
    }

    /**
     * Apply a Plugnotas summary (read-back or webhook-confirmed) to the row.
     * The only place a nota changes state from Plugnotas' side.
     */
    public function apply(FiscalInvoice $row, array $summary): FiscalInvoice
    {
        $situation = strtoupper((string) ($summary['situacao'] ?? $summary['status'] ?? ''));
        $message = is_string($summary['mensagem'] ?? null) ? $summary['mensagem'] : null;

        $base = [
            'checked_at' => now(),
            'provider_id' => $row->provider_id ?? (isset($summary['id']) ? (string) $summary['id'] : null),
            'message' => $message ?? $row->message,
        ];

        $updated = match ($situation) {
            'CONCLUIDO' => $row->status === FiscalInvoiceStatus::Cancelling ? $base : $base + [
                'status' => FiscalInvoiceStatus::Issued,
                'number' => isset($summary['numeroNfse']) ? (string) $summary['numeroNfse'] : ($row->number),
                'verification_code' => isset($summary['codigoVerificacao']) ? (string) $summary['codigoVerificacao'] : $row->verification_code,
                'issued_at' => $row->issued_at ?? now(),
            ],
            'CANCELADO' => $base + [
                'status' => FiscalInvoiceStatus::Cancelled,
                'cancelled_at' => $row->cancelled_at ?? now(),
            ],
            'REJEITADO', 'DENEGADO' => $row->status === FiscalInvoiceStatus::Cancelling
                // A cancellation the prefeitura refused leaves the nota valid.
                // The request is dropped, or the sweep would ask again forever;
                // an operator decides what happens next.
                ? $base + ['status' => FiscalInvoiceStatus::Issued, 'meta' => $row->withMeta(['cancel_refused' => $message ?? 'Cancellation refused', 'cancel_requested_at' => null])]
                : $base + ['status' => FiscalInvoiceStatus::Rejected],
            default => $base,
        };

        $before = $row->status;
        $row->update($updated);

        if ($before !== $row->status) {
            Log::info('Nota fiscal moved', [
                'fiscal_invoice_id' => $row->id,
                'from' => $before->value,
                'to' => $row->status->value,
                'number' => $row->number,
                'message' => $message,
            ]);
        }

        // A refund that arrived while the nota was still with the prefeitura
        // is acted on the moment it can be.
        if ($row->status === FiscalInvoiceStatus::Issued && $row->metaValue('cancel_requested_at')) {
            return $this->cancel($row);
        }

        return $row;
    }

    /**
     * The invoice was refunded: the nota it produced must not stand.
     */
    public function cancelFor(Invoice $invoice): void
    {
        $row = FiscalInvoice::query()->where('invoice_id', $invoice->id)->first();

        if (! $row) {
            return;
        }

        match ($row->status) {
            FiscalInvoiceStatus::Issued => $this->cancel($row),
            FiscalInvoiceStatus::Processing => $row->update(['meta' => $row->withMeta(['cancel_requested_at' => now()->toIso8601String()])]),
            // Never reached the prefeitura: there is nothing to cancel.
            FiscalInvoiceStatus::Pending, FiscalInvoiceStatus::Rejected, FiscalInvoiceStatus::Failed => $row->update([
                'status' => FiscalInvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'message' => 'Invoice refunded before the nota was issued.',
            ]),
            default => null,
        };
    }

    public function cancel(FiscalInvoice $row): FiscalInvoice
    {
        if ($row->status !== FiscalInvoiceStatus::Issued || $row->provider_id === null) {
            return $row;
        }

        try {
            $this->client->cancel($row->provider_id, 'Pagamento estornado');
        } catch (PlugnotasException $e) {
            $row->update(['meta' => $row->withMeta([
                'cancel_requested_at' => $row->metaValue('cancel_requested_at') ?? now()->toIso8601String(),
                'cancel_error' => $e->getMessage(),
            ])]);

            Log::error('Nota fiscal cancellation failed', [
                'fiscal_invoice_id' => $row->id,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return $row;
        }

        $row->update([
            'status' => FiscalInvoiceStatus::Cancelling,
            'meta' => $row->withMeta(['cancel_requested_at' => $row->metaValue('cancel_requested_at') ?? now()->toIso8601String(), 'cancel_error' => null]),
        ]);

        return $row;
    }

    /**
     * Send a rejected or failed nota again, under a new reference — Plugnotas
     * keeps the rejected one under the old reference and would refuse a second.
     */
    public function retry(FiscalInvoice $row): FiscalInvoice
    {
        if (! $row->status->needsOperator()) {
            return $row;
        }

        $attempt = $row->attempt + 1;

        $row->update([
            'status' => FiscalInvoiceStatus::Pending,
            'attempt' => $attempt,
            'reference' => $this->referenceFor($row->invoice, $attempt),
            'provider_id' => null,
            'protocol' => null,
            'submitted_at' => null,
            'checked_at' => null,
            'message' => null,
            'meta' => $row->withMeta(['previous_reference' => $row->reference]),
        ]);

        IssueFiscalInvoice::dispatch($row->id);

        return $row;
    }

    /**
     * Download the authorized PDF or XML. Only for an issued (or later
     * cancelled) nota — before that there is no document.
     */
    public function document(FiscalInvoice $row, string $kind = 'pdf'): \Illuminate\Http\Client\Response
    {
        return $this->client->document((string) $row->provider_id, $kind);
    }

    /**
     * idIntegracao. Stable for an invoice and attempt, so every retry of the
     * same submission names the same nota — and short, because Plugnotas caps
     * it at 50 characters.
     */
    public function referenceFor(Invoice $invoice, int $attempt): string
    {
        return 'pingly-inv-'.$invoice->id.($attempt > 1 ? '-'.$attempt : '');
    }

    /**
     * Why this tomador cannot be named on a nota, or null. The CPF/CNPJ is
     * required at checkout in Brazil, so this mostly catches a profile edited
     * between the payment and the nota.
     */
    public function tomadorProblem(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return 'Workspace not found.';
        }

        $digits = preg_replace('/\D/', '', (string) $tenant->billing_document_number);

        if (! in_array(strlen($digits), [11, 14], true)) {
            return 'The workspace has no CPF/CNPJ on its billing profile.';
        }

        if (! filled($tenant->billing_name)) {
            return 'The workspace has no billing name on its billing profile.';
        }

        return null;
    }

    /** The service line on the nota, in Portuguese — it is a Brazilian document. */
    public function describe(Invoice $invoice): string
    {
        $period = $invoice->period_start && $invoice->period_end
            ? ' — período de '.$invoice->period_start->format('d/m/Y').' a '.$invoice->period_end->format('d/m/Y')
            : '';

        $line = match ($invoice->purpose ?? InvoicePurpose::Subscription) {
            InvoicePurpose::Subscription => 'Assinatura da plataforma Pingly'
                .(($plan = $invoice->subscription?->plan?->name) ? ' — plano '.$plan : '')
                .$period,
            InvoicePurpose::CreditTopup => 'Recarga de saldo pré-pago da plataforma Pingly',
            InvoicePurpose::ApiwayPurchase => 'Instância API Way (WhatsApp) na plataforma Pingly'
                .(($qty = $invoice->apiwaySubscription?->quantity) ? ' — '.$qty.' instância(s)' : ''),
            InvoicePurpose::ApiwayRenewal => 'Renovação de instância API Way (WhatsApp) na plataforma Pingly'.$period,
            InvoicePurpose::TrainedAgentPurchase => 'Agente de IA treinado na plataforma Pingly'
                .(($name = $invoice->trainedAgentHire?->blueprint?->name) ? ' — '.$name : ''),
        };

        // `|` is Plugnotas' line break on the printed nota.
        return $line.'|Fatura nº '.$invoice->id;
    }

    /** The NFS-e as Plugnotas wants it. */
    public function payload(FiscalInvoice $row, Invoice $invoice, string $description): array
    {
        $tenant = $invoice->tenant;
        $amount = round($invoice->amount_cents / 100, 2);

        $iss = array_filter([
            'aliquota' => PlugnotasConfig::issRate(),
            'tipoTributacao' => PlugnotasConfig::issTaxType(),
            'exigibilidade' => PlugnotasConfig::issRequirement(),
        ], fn ($value) => $value !== null);

        $service = array_filter([
            'codigo' => PlugnotasConfig::serviceCode(),
            'codigoTributacao' => PlugnotasConfig::taxCode(),
            'cnae' => PlugnotasConfig::cnae(),
            'discriminacao' => $description,
            'iss' => $iss,
            'valor' => ['servico' => $amount],
        ], fn ($value) => $value !== null);

        $tomador = array_filter([
            'cpfCnpj' => preg_replace('/\D/', '', (string) $tenant->billing_document_number),
            'razaoSocial' => mb_substr((string) $tenant->billing_name, 0, 150),
            'email' => $tenant->user?->email,
            'endereco' => $this->address($tenant),
        ], fn ($value) => $value !== null && $value !== '');

        return array_filter([
            'idIntegracao' => $row->reference,
            'enviarEmail' => PlugnotasConfig::sendEmail(),
            'prestador' => ['cpfCnpj' => PlugnotasConfig::prestadorCnpj()],
            'tomador' => $tomador,
            'servico' => [$service],
            'informacoesComplementares' => PlugnotasConfig::notes(),
        ], fn ($value) => $value !== null);
    }

    /**
     * The tomador's address, only when complete. A partial address is worse
     * than none: the prefeitura rejects a CEP without a city code, while most
     * of them accept a tomador with no address at all.
     */
    public function address(Tenant $tenant): ?array
    {
        $a = $tenant->billing_address;

        if (! is_array($a)) {
            return null;
        }

        $required = ['cep', 'logradouro', 'numero', 'bairro', 'codigo_cidade', 'estado'];

        foreach ($required as $key) {
            if (! filled($a[$key] ?? null)) {
                return null;
            }
        }

        return array_filter([
            'cep' => preg_replace('/\D/', '', (string) $a['cep']),
            'logradouro' => (string) $a['logradouro'],
            'numero' => (string) $a['numero'],
            'complemento' => filled($a['complemento'] ?? null) ? (string) $a['complemento'] : null,
            'bairro' => (string) $a['bairro'],
            'codigoCidade' => preg_replace('/\D/', '', (string) $a['codigo_cidade']),
            'descricaoCidade' => filled($a['cidade'] ?? null) ? (string) $a['cidade'] : null,
            'estado' => strtoupper((string) $a['estado']),
        ], fn ($value) => $value !== null);
    }

    protected function fail(FiscalInvoice $row, string $message): FiscalInvoice
    {
        $row->update(['status' => FiscalInvoiceStatus::Failed, 'message' => $message, 'checked_at' => now()]);

        Log::error('Nota fiscal not issued', [
            'fiscal_invoice_id' => $row->id,
            'invoice_id' => $row->invoice_id,
            'tenant_id' => $row->tenant_id,
            'error' => $message,
        ]);

        return $row;
    }
}
