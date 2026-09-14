<?php

namespace App\Services\Integrations\Invoices;

use App\Enums\Flow\FlowInvoiceStatus;
use App\Models\FlowInvoice;
use App\Models\Integration;
use App\Services\Integrations\Concerns\CallsProvider;
use App\Services\Integrations\ManagesWebhooks;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Spedy: service invoices (NFS-e) issued by the workspace's company.
 *
 * What Spedy is like, and how each part shaped this class:
 *
 *  - The API key belongs to one *company*, not the account, and the base URL
 *    does not tell sandbox from production — the integration's `sandbox`
 *    switch does.
 *  - `integrationId` (≤ 36 characters — our reference is exactly that) is
 *    unique per company, and POSTing it again *updates* the invoice rather
 *    than creating one. So an existing invoice is looked up first and adopted:
 *    a retried request must never rewrite an invoice already on its way to the
 *    prefeitura.
 *  - Accepting the request is not issuing it. The answer comes later as
 *    `authorized`, `rejected` or `denied`, and a rejection is not an HTTP
 *    error — it is a status with `processingDetail`, read back like any other.
 *  - The PDF and XML sit behind the API key (see InvoiceIssuer::document()).
 *  - Webhooks are per account, one event per registration, and are switched
 *    off after five failed deliveries without replaying what was missed —
 *    which is why flow-invoices:sync polls regardless.
 */
class SpedyIssuer implements InvoiceIssuer, ManagesWebhooks
{
    use CallsProvider;

    public const PRODUCTION_URL = 'https://api.spedy.com.br/v1';

    public const SANDBOX_URL = 'https://sandbox-api.spedy.com.br/v1';

    /** The whole lifecycle in one event — Spedy's own recommendation. */
    public const WEBHOOK_EVENT = 'invoice.status_changed';

    /**
     * An invoice still in the authority's hands for this long is asked about
     * directly (`check-status` queries the prefeitura) instead of waiting for
     * Spedy's own schedule.
     */
    private const CHECK_STATUS_AFTER_MINUTES = 10;

    /** A request that never produced an invoice we can find is called failed after this. */
    private const LOST_REQUEST_MINUTES = 15;

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->integration->setting('sandbox') ? self::SANDBOX_URL : self::PRODUCTION_URL)
            ->withHeaders(['X-Api-Key' => (string) $this->integration->credential('api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout(25)
            ->connectTimeout(8);
    }

    /**
     * Spedy's error body has no fixed schema yet ("the exact format may vary by
     * endpoint"), so every shape seen in the wild is read.
     */
    protected function errorFrom(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            return [null, null];
        }

        $parts = [];

        foreach (['message', 'error', 'title', 'detail'] as $key) {
            if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                $parts[] = $json[$key];
            }
        }

        if (isset($json['errors']) && is_array($json['errors'])) {
            array_walk_recursive($json['errors'], function ($value) use (&$parts) {
                if (is_string($value) && $value !== '') {
                    $parts[] = $value;
                }
            });
        }

        $code = $json['code'] ?? ($json['errors'][0]['code'] ?? null);

        return [
            $parts !== [] ? implode('; ', array_unique($parts)) : null,
            is_scalar($code) ? (string) $code : null,
        ];
    }

    public function verify(): array
    {
        // Any company key can list its own invoices; a bad key is a 403.
        $this->call(fn (PendingRequest $http) => $http->get('/service-invoices', ['pageSize' => 1]), 'verify');

        $account = [];

        try {
            // Only the account's main company may read /companies. For the
            // others the card simply shows no company name.
            $companies = $this->call(fn (PendingRequest $http) => $http->get('/companies', ['pageSize' => 1]), 'companies');
            $company = $companies['items'][0] ?? null;

            if (is_array($company)) {
                $account = [
                    'account_name' => $company['tradeName'] ?? $company['name'] ?? $company['legalName'] ?? null,
                    'account_id' => isset($company['federalTaxNumber']) ? (string) $company['federalTaxNumber'] : null,
                ];
            }
        } catch (\Throwable) {
            // Not the main company's key — nothing wrong with it.
        }

        return array_filter(array_merge($account, [
            'environment' => $this->integration->setting('sandbox') ? 'sandbox' : 'production',
        ]));
    }

    public function issue(InvoiceRequest $invoice): InvoiceResult
    {
        $existing = $this->findByReference($invoice->reference);

        if ($existing !== null) {
            return $this->resultFrom($existing);
        }

        $json = $this->call(
            fn (PendingRequest $http) => $http->post('/service-invoices', $this->payload($invoice)),
            'issue_invoice',
        );

        return $this->resultFrom($json);
    }

    public function fetch(FlowInvoice $invoice): InvoiceResult
    {
        $data = $invoice->provider_invoice_id
            ? $this->call(fn (PendingRequest $http) => $http->get('/service-invoices/'.rawurlencode($invoice->provider_invoice_id)), 'fetch_invoice')
            : $this->findByReference($invoice->reference);

        if (! is_array($data) || $data === []) {
            // The request timed out before Spedy answered, and nothing ever
            // arrived under our reference: it never landed.
            if ($invoice->created_at && $invoice->created_at->lt(now()->subMinutes(self::LOST_REQUEST_MINUTES))) {
                return new InvoiceResult(
                    status: FlowInvoiceStatus::Failed,
                    failureReason: 'A solicitação da nota fiscal não chegou à Spedy. Nenhuma nota foi emitida.',
                    providerStatus: 'not_found',
                );
            }

            return new InvoiceResult(FlowInvoiceStatus::Processing);
        }

        $status = (string) ($data['status'] ?? '');

        if (in_array($status, ['received', 'inContingent'], true)
            && isset($data['id'])
            && $invoice->created_at
            && $invoice->created_at->lt(now()->subMinutes(self::CHECK_STATUS_AFTER_MINUTES))) {
            try {
                $checked = $this->call(
                    fn (PendingRequest $http) => $http->post('/service-invoices/'.rawurlencode((string) $data['id']).'/check-status'),
                    'check_status',
                );
                $data = $checked !== [] ? $checked : $data;
            } catch (\Throwable) {
                // Refused while still queued at Spedy; the plain read stands.
            }
        }

        return $this->resultFrom($data);
    }

    public function document(FlowInvoice $invoice, string $format): ?string
    {
        if (! $invoice->provider_invoice_id) {
            return null;
        }

        $format = $format === 'xml' ? 'xml' : 'pdf';

        try {
            $response = $this->request()
                ->withHeaders(['Accept' => $format === 'xml' ? 'application/xml' : 'application/pdf'])
                ->timeout(40)
                ->get('/service-invoices/'.rawurlencode($invoice->provider_invoice_id).'/'.$format);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw UpstreamError::exception(UpstreamProvider::Spedy, $e->getMessage(), status: 504, context: [
                'integration_id' => $this->integration->id,
                'action' => "download_{$format}",
            ], previous: $e);
        }

        // Not authorized (yet, or any more): no file to hand over.
        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }

        if ($response->failed()) {
            [$message, $code] = $this->errorFrom($response);

            throw UpstreamError::exception(
                UpstreamProvider::Spedy,
                $message ?: $response->body(),
                $code ?? (string) $response->status(),
                $response->status(),
                ['integration_id' => $this->integration->id, 'action' => "download_{$format}"],
            );
        }

        return $response->body();
    }

    public function webhookReferences(Request $request): array
    {
        // The body is only a pointer — the invoice is read back with the
        // workspace's key — so Spedy's HMAC signature is not needed to act on
        // it safely, and its account-wide secret is not stored here.
        $data = (array) $request->input('data', []);

        return array_values(array_unique(array_filter([
            is_string($data['integrationId'] ?? null) && str_starts_with($data['integrationId'], 'pingly-nf-') ? $data['integrationId'] : null,
            is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : null,
        ])));
    }

    public function registerWebhook(string $url, string $secret): array
    {
        $existing = $this->call(fn (PendingRequest $http) => $http->get('/webhooks'), 'list_webhooks');
        $list = array_is_list($existing) ? $existing : ($existing['items'] ?? $existing['data'] ?? []);

        foreach ((array) $list as $webhook) {
            if (is_array($webhook) && ($webhook['url'] ?? null) === $url && ($webhook['event'] ?? null) === self::WEBHOOK_EVENT && isset($webhook['id'])) {
                return ['webhook_ids' => [(string) $webhook['id']]];
            }
        }

        $json = $this->call(fn (PendingRequest $http) => $http->post('/webhooks', [
            'event' => self::WEBHOOK_EVENT,
            'url' => $url,
        ]), 'register_webhook');

        return ['webhook_ids' => array_values(array_filter([$json['id'] ?? null]))];
    }

    public function unregisterWebhook(array $meta): void
    {
        foreach ((array) ($meta['webhook_ids'] ?? []) as $id) {
            try {
                $this->call(fn (PendingRequest $http) => $http->delete('/webhooks/'.rawurlencode((string) $id)), 'delete_webhook');
            } catch (\Throwable) {
                // See OpenPixGateway::unregisterWebhook().
            }
        }
    }

    /**
     * The invoice already filed under our reference, if any.
     *
     * @return array<string, mixed>|null
     */
    private function findByReference(string $reference): ?array
    {
        $json = $this->call(fn (PendingRequest $http) => $http->get('/service-invoices', [
            'integrationId' => $reference,
            'pageSize' => 1,
        ]), 'find_invoice');

        $found = $json['items'][0] ?? null;

        return is_array($found) ? $found : null;
    }

    /** @return array<string, mixed> */
    private function payload(InvoiceRequest $invoice): array
    {
        $codes = [];

        foreach (['federal_service_code' => 'federalServiceCode', 'city_service_code' => 'cityServiceCode', 'cnae_code' => 'cnaeCode'] as $setting => $field) {
            $value = trim((string) $this->integration->setting($setting, ''));

            if ($value !== '') {
                $codes[$field] = $value;
            }
        }

        return array_merge($codes, array_filter([
            'integrationId' => $invoice->reference,
            // Spedy reads dates in São Paulo time.
            'effectiveDate' => CarbonImmutable::now('America/Sao_Paulo')->format('Y-m-d\TH:i:s'),
            'description' => Str::limit($invoice->description, 2000, ''),
            'sendEmailToCustomer' => $invoice->sendEmail,
            'total' => ['invoiceAmount' => round($invoice->amountCents / 100, 2)],
            'receiver' => array_filter([
                'name' => Str::limit($invoice->customerName, 115, ''),
                'federalTaxNumber' => $invoice->customerDocument,
                'email' => $invoice->customerEmail,
                'address' => $this->address($invoice->customerAddress),
            ], fn ($value) => $value !== null && $value !== ''),
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @param  array<string, string>|null  $address
     * @return array<string, mixed>|null
     */
    private function address(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $city = array_filter([
            'name' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
        ]);

        $mapped = array_filter([
            'street' => $address['street'] ?? null,
            'number' => $address['number'] ?? null,
            'additionalInformation' => $address['complement'] ?? null,
            'district' => $address['district'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'city' => $city !== [] ? $city : null,
        ]);

        return $mapped === [] ? null : array_merge($mapped, ['country' => 'BRA']);
    }

    /** @param  array<string, mixed>  $data */
    private function resultFrom(array $data): InvoiceResult
    {
        $raw = (string) ($data['status'] ?? 'enqueued');

        $status = match (true) {
            $raw === 'authorized' => FlowInvoiceStatus::Issued,
            $raw === 'canceled' => FlowInvoiceStatus::Cancelled,
            in_array($raw, ['rejected', 'denied', 'removed', 'disabled'], true) => FlowInvoiceStatus::Failed,
            // created, enqueued, received, inContingent — and whatever Spedy
            // names "being cancelled", which is not a state of ours.
            default => FlowInvoiceStatus::Processing,
        };

        $issuedOn = $data['authorization']['date'] ?? $data['issuedOn'] ?? null;

        return new InvoiceResult(
            status: $status,
            providerInvoiceId: isset($data['id']) ? (string) $data['id'] : null,
            number: isset($data['number']) && $data['number'] !== '' && $data['number'] !== 0 ? (string) $data['number'] : null,
            issuedAt: $status === FlowInvoiceStatus::Issued && is_string($issuedOn) ? CarbonImmutable::parse($issuedOn, 'America/Sao_Paulo') : null,
            failureReason: $status === FlowInvoiceStatus::Failed ? $this->rejectionReason($data) : null,
            providerStatus: $raw,
        );
    }

    /**
     * Why the authority refused, in our words — the prefeitura's own sentence
     * goes to the log under a reference, like every other outside error.
     *
     * @param  array<string, mixed>  $data
     */
    private function rejectionReason(array $data): string
    {
        $detail = (array) ($data['processingDetail'] ?? []);
        $raw = is_string($detail['message'] ?? null) && $detail['message'] !== '' ? $detail['message'] : 'invoice rejected';
        $code = is_scalar($detail['code'] ?? null) ? (string) $detail['code'] : 'invoice_rejected';

        return UpstreamError::message(UpstreamProvider::Spedy, $raw, $code, 422, [
            'integration_id' => $this->integration->id,
            'provider_invoice_id' => $data['id'] ?? null,
            'action' => 'invoice_rejected',
        ]);
    }
}
