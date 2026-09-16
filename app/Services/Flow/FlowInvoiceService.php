<?php

namespace App\Services\Flow;

use App\Enums\Flow\FlowInvoiceStatus;
use App\Enums\Integration\IntegrationCategory;
use App\Events\FlowInvoiceUpdated;
use App\Exceptions\UpstreamServiceException;
use App\Models\Contact;
use App\Models\FlowInvoice;
use App\Models\FlowNode;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Models\Integration;
use App\Services\Contact\ContactIdentity;
use App\Services\Conversation\SystemMessage;
use App\Services\Integrations\IntegrationDrivers;
use App\Services\Integrations\Invoices\InvoiceRequest;
use App\Services\Integrations\Invoices\InvoiceResult;
use App\Services\Money\MarketMoney;
use App\Support\Errors\UpstreamError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The life of a nota fiscal a flow asked for: requested, then settled once.
 *
 * The same exactly-once shape as FlowPaymentService — webhook, deadline job and
 * polling sweep all call settle(), which moves the row out of `processing` under
 * a row lock — with one difference that shapes the rest: the flow's deadline and
 * the invoice's fate are two separate things.
 *
 * A Pix that expires is unpaid, full stop. An invoice whose prefeitura has not
 * answered in 30 minutes is not rejected; it is late, and it may well be
 * authorized an hour from now. So when the flow stops waiting, the row stays
 * `processing` (that is the truth) and is only marked released — the flow takes
 * `failed`, the sweep keeps following the invoice, and an authorization that
 * lands afterwards is recorded and noted for the agents without replaying a
 * branch the customer has already been taken down.
 */
class FlowInvoiceService
{
    /**
     * Issue the invoice a node describes.
     *
     * Always returns a row: `processing` (or already final) when the provider
     * accepted the request, `failed` with our own sentence when anything — the
     * node, the integration, the provider — refused.
     *
     * @param  callable(string): string  $interpolate
     */
    public function createForNode(FlowState $flowState, FlowNode $node, callable $interpolate): FlowInvoice
    {
        $conversation = $flowState->conversation;
        $connection = $conversation->connection;
        $tenantId = (int) $connection->tenant_id;
        $contact = $conversation->contact;
        $data = $node->data ?? [];

        $integrationId = (int) ($data['integration_id'] ?? 0);
        $integration = $integrationId > 0
            ? Integration::forTenant($tenantId)->inCategory(IntegrationCategory::Invoice)->find($integrationId)
            : null;

        $rawAmount = trim($interpolate((string) ($data['amount'] ?? '')));
        $amountCents = PaymentNodes::parseAmount($rawAmount);
        $description = Str::limit(trim($interpolate((string) ($data['description'] ?? ''))), 2000, '');

        $typedName = trim($interpolate((string) ($data['customer_name'] ?? '')));
        $name = $typedName !== '' ? $typedName : (string) ContactIdentity::name($contact);
        $rawDocument = trim($interpolate((string) ($data['customer_document'] ?? '')));
        $document = InvoiceNodes::document($rawDocument);

        $invoice = new FlowInvoice([
            'tenant_id' => $tenantId,
            'integration_id' => $integration?->id,
            'provider' => $integration?->provider,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact?->id,
            'flow_id' => $flowState->flow_id,
            'flow_state_id' => $flowState->id,
            'flow_node_id' => $node->id,
            'flow_payment_id' => $this->paymentFor($flowState)?->id,
            'reference' => 'pingly-nf-'.Str::lower((string) Str::ulid()),
            'amount_cents' => $amountCents ?? 0,
            // The workspace's own money — see FlowPaymentService.
            'currency' => $connection->tenant?->currency() ?? MarketMoney::baseCurrency(),
            'description' => $description !== '' ? $description : null,
            'customer_name' => $name !== '' ? Str::limit($name, 255, '') : null,
            'customer_document' => $document ?? ($rawDocument !== '' ? Str::limit($rawDocument, 20, '') : null),
            'customer_email' => $this->customerEmail($data, $interpolate, $contact),
            'status' => FlowInvoiceStatus::Processing,
            'wait_until' => CarbonImmutable::now()->addMinutes(InvoiceNodes::waitMinutes($data)),
        ]);

        $refusal = match (true) {
            $integration === null => 'A integração de nota fiscal deste nó não existe mais. Escolha outra em Configurações → Integrações e salve o fluxo.',
            ! $integration->enabled => "A integração \"{$integration->name}\" está desativada.",
            $amountCents === null => 'O valor da nota não é válido'.($rawAmount !== '' ? ": \"{$rawAmount}\"." : ' (ficou vazio).'),
            $description === '' => 'A descrição do serviço ficou vazia. Uma nota fiscal precisa dizer o que foi vendido.',
            $document === null => $rawDocument === ''
                ? 'O CPF/CNPJ do cliente ficou vazio. Colete-o antes com um nó de Resposta.'
                : "O CPF/CNPJ do cliente não é válido: \"{$rawDocument}\".",
            $name === '' => 'O nome do cliente ficou vazio.',
            default => null,
        };

        if ($refusal !== null) {
            return $this->markFailed($invoice, $refusal);
        }

        // Saved before the network call so the reference is ours before the
        // provider sees it — a request that times out after the provider
        // accepted it still leaves a row the sweep can reconcile.
        $invoice->save();

        $request = new InvoiceRequest(
            reference: $invoice->reference,
            amountCents: $amountCents,
            description: $description,
            customerName: $name,
            customerDocument: $document,
            customerEmail: $invoice->customer_email,
            customerAddress: InvoiceNodes::address($data, $interpolate),
            sendEmail: InvoiceNodes::sendsEmail($data) && $invoice->customer_email !== null,
            notificationUrl: $integration->webhookUrl(),
        );

        try {
            $result = IntegrationDrivers::invoice($integration)->issue($request);
        } catch (UpstreamServiceException $e) {
            $integration->recordError($e->getMessage());

            return $this->markFailed($invoice, $e->getMessage());
        } catch (\Throwable $e) {
            $message = UpstreamError::messageFrom($integration->provider->upstream(), $e, [
                'integration_id' => $integration->id,
                'flow_invoice_id' => $invoice->id,
            ]);
            $integration->recordError($message);

            return $this->markFailed($invoice, $message);
        }

        $invoice->forceFill(array_merge($this->fieldsFrom($result), [
            'status' => $result->status,
            'settled_at' => $result->status->isFinal() ? now() : null,
            'failure_reason' => $result->status === FlowInvoiceStatus::Failed
                ? Str::limit($result->failureReason ?? 'A nota fiscal foi rejeitada.', 480)
                : null,
            'meta' => array_filter(['provider_status' => $result->providerStatus]),
        ]))->save();

        $integration->recordUse();

        return $invoice;
    }

    /**
     * Ask the provider where an open invoice stands, and settle it if it ended.
     * Throws when the provider cannot be asked (the webhook answers 500 so it
     * is retried; the sweep logs and tries next minute).
     */
    public function refresh(FlowInvoice $invoice): FlowInvoice
    {
        $integration = $invoice->integration;

        if ($integration === null || ! $this->worthAsking($invoice)) {
            return $invoice;
        }

        try {
            $result = IntegrationDrivers::invoice($integration)->fetch($invoice);
        } finally {
            FlowInvoice::whereKey($invoice->id)->update(['last_checked_at' => now()]);
        }

        $this->settle($invoice, $result);

        return $invoice->fresh();
    }

    /**
     * The flow's deadline passed. Asked one last time — an authorization that
     * arrived without its webhook still takes `issued` — and, if the authority
     * is still silent, the flow is let go down `failed` while the invoice stays
     * open to be followed up.
     */
    public function release(FlowInvoice $invoice): void
    {
        if (! $invoice->isProcessing() || ($invoice->meta['released_at'] ?? null) !== null) {
            return;
        }

        if ($invoice->wait_until && $invoice->wait_until->isFuture()) {
            return;
        }

        try {
            $invoice = $this->refresh($invoice);
        } catch (\Throwable $e) {
            Log::warning('FlowInvoiceService: provider unreachable at the deadline, releasing the flow anyway', [
                'flow_invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $invoice->isProcessing()) {
            return;
        }

        $released = DB::transaction(function () use ($invoice) {
            $locked = FlowInvoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isProcessing() || ($locked->meta['released_at'] ?? null) !== null) {
                return false;
            }

            $locked->forceFill([
                'meta' => array_merge($locked->meta ?? [], ['released_at' => now()->toIso8601String()]),
            ])->save();

            return true;
        });

        if (! $released) {
            return;
        }

        $invoice->refresh();

        $this->note($invoice, InvoiceNodes::INFO_STILL_PROCESSING);

        (new FlowExecutor)->resumeFromInvoice($invoice, released: true);
    }

    /**
     * Apply what the provider said. Moves a row out of `processing` once; only
     * that first move resumes the flow, and only if the flow was still waiting.
     *
     * Returns whether anything changed.
     */
    public function settle(FlowInvoice $invoice, InvoiceResult $result): bool
    {
        $outcome = DB::transaction(function () use ($invoice, $result) {
            $locked = FlowInvoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $meta = array_filter(
                array_merge($locked->meta ?? [], ['provider_status' => $result->providerStatus]),
                fn ($value) => $value !== null,
            );

            if ($locked->status === FlowInvoiceStatus::Processing) {
                if (! $result->status->isFinal()) {
                    // Still in the authority's hands; keep whatever we learned
                    // (an id, a number) without deciding anything.
                    $locked->forceFill(array_merge($this->fieldsFrom($result, $locked), ['meta' => $meta]))->save();

                    return null;
                }

                $locked->forceFill(array_merge($this->fieldsFrom($result, $locked), [
                    'status' => $result->status,
                    'issued_at' => $result->status === FlowInvoiceStatus::Issued ? ($result->issuedAt ?? now()) : null,
                    'settled_at' => now(),
                    'failure_reason' => $result->status === FlowInvoiceStatus::Issued
                        ? null
                        : Str::limit($result->failureReason ?? 'A nota fiscal foi rejeitada.', 480),
                    'meta' => $meta,
                ]))->save();

                return isset($meta['released_at']) ? 'late' : 'settled';
            }

            // Authorized earlier, now cancelled at the provider (by hand, in its
            // own dashboard). Recorded so the list tells the truth; nothing to
            // resume — the flow finished long ago.
            if ($locked->status === FlowInvoiceStatus::Issued && $result->status === FlowInvoiceStatus::Cancelled) {
                $locked->forceFill(['status' => FlowInvoiceStatus::Cancelled, 'meta' => $meta])->save();

                return 'cancelled';
            }

            // Some platforms authorize first and render the PDF a moment later.
            // A link that shows up afterwards is filled in quietly.
            if ($locked->status === FlowInvoiceStatus::Issued && (! $locked->pdf_url || ! $locked->number)) {
                $fields = array_filter($this->fieldsFrom($result, $locked));

                if ($fields !== []) {
                    $locked->forceFill($fields)->save();
                }
            }

            return null;
        });

        if ($outcome === null) {
            return false;
        }

        $invoice->refresh();

        match ($outcome) {
            'late' => $this->note($invoice, $invoice->status === FlowInvoiceStatus::Issued ? InvoiceNodes::INFO_ISSUED_LATE : InvoiceNodes::INFO_FAILED),
            'cancelled' => $this->note($invoice, InvoiceNodes::INFO_CANCELLED),
            default => null,
        };

        if ($outcome === 'settled') {
            $this->note($invoice, $invoice->status === FlowInvoiceStatus::Issued ? InvoiceNodes::INFO_ISSUED : InvoiceNodes::INFO_FAILED);

            (new FlowExecutor)->resumeFromInvoice($invoice);
        }

        return true;
    }

    /**
     * Tell the thread and every open dashboard what happened to an invoice.
     */
    public function note(FlowInvoice $invoice, string $code): void
    {
        $conversation = $invoice->conversation;
        $amount = $invoice->formattedAmount();
        $provider = $invoice->provider?->label() ?? '—';

        if ($conversation !== null) {
            $number = $invoice->number ? " nº {$invoice->number}" : '';

            $body = match ($code) {
                InvoiceNodes::INFO_REQUESTED => "Invoice requested: {$amount} via {$provider}.",
                InvoiceNodes::INFO_ISSUED => "Invoice{$number} issued: {$amount} via {$provider}.",
                InvoiceNodes::INFO_STILL_PROCESSING => "The invoice of {$amount} is still being processed. The flow moved on.",
                InvoiceNodes::INFO_ISSUED_LATE => "The invoice{$number} of {$amount} was issued after the flow had moved on.",
                InvoiceNodes::INFO_CANCELLED => "The invoice{$number} of {$amount} was cancelled.",
                default => "The invoice of {$amount} could not be issued: {$invoice->failure_reason}",
            };

            SystemMessage::info($conversation, $body, $code, array_filter([
                'amount_cents' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'provider' => $invoice->provider?->value,
                'number' => $invoice->number,
                'reason' => $code === InvoiceNodes::INFO_FAILED ? $invoice->failure_reason : null,
            ], fn ($value) => $value !== null));
        }

        try {
            broadcast(new FlowInvoiceUpdated($invoice));
        } catch (\Throwable $e) {
            Log::warning('FlowInvoiceService: could not broadcast the invoice update', [
                'flow_invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the provider is still worth asking about this invoice: open, and
     * not so old that nobody is waiting for the answer any more.
     */
    private function worthAsking(FlowInvoice $invoice): bool
    {
        if ($invoice->isProcessing()) {
            return true;
        }

        // An issued invoice whose number had not been assigned yet is asked
        // again for a little while — some prefeituras number it a moment later.
        return $invoice->status === FlowInvoiceStatus::Issued
            && ! $invoice->number
            && $invoice->issued_at
            && $invoice->issued_at->gt(now()->subHour());
    }

    /**
     * The document fields a result carries, never overwriting a value we have
     * with a blank one.
     *
     * @return array<string, mixed>
     */
    private function fieldsFrom(InvoiceResult $result, ?FlowInvoice $current = null): array
    {
        return [
            'provider_invoice_id' => $result->providerInvoiceId ?: $current?->provider_invoice_id,
            'number' => $result->number ?: $current?->number,
            'pdf_url' => $result->pdfUrl ?: $current?->pdf_url,
            'xml_url' => $result->xmlUrl ?: $current?->xml_url,
        ];
    }

    private function markFailed(FlowInvoice $invoice, string $reason): FlowInvoice
    {
        $invoice->forceFill([
            'status' => FlowInvoiceStatus::Failed,
            'failure_reason' => Str::limit($reason, 480),
            'settled_at' => now(),
        ])->save();

        Log::info('FlowInvoiceService: invoice not requested', [
            'flow_invoice_id' => $invoice->id,
            'integration_id' => $invoice->integration_id,
            'reason' => $reason,
        ]);

        return $invoice;
    }

    /**
     * The charge this invoice follows, when a payment node ran earlier in the
     * same flow state — its reference is what {{payment_id}} holds.
     */
    private function paymentFor(FlowState $flowState): ?FlowPayment
    {
        $reference = ($flowState->state_data ?? [])['payment_id'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return FlowPayment::where('reference', $reference)
            ->where('conversation_id', $flowState->conversation_id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(string): string  $interpolate
     */
    private function customerEmail(array $data, callable $interpolate, ?Contact $contact): ?string
    {
        $typed = strtolower(trim($interpolate((string) ($data['customer_email'] ?? ''))));

        if ($typed !== '' && filter_var($typed, FILTER_VALIDATE_EMAIL)) {
            return $typed;
        }

        return ContactIdentity::for($contact)['email'] ?? null;
    }
}
