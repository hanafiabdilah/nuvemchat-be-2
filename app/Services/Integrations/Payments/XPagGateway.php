<?php

namespace App\Services\Integrations\Payments;

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Integration\IntegrationProvider;
use App\Exceptions\UpstreamServiceException;
use App\Models\FlowPayment;
use App\Models\Integration;
use App\Services\Integrations\Concerns\CallsProvider;
use App\Support\Errors\UpstreamError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * XPag (xpag.global): Pix charges on the workspace's own account.
 *
 * Five things about XPag shape this class:
 *
 *  - One host for both environments. The credentials decide: a Client ID
 *    starting with `xpagsandbox_` is the sandbox, which never moves money.
 *    Authenticated with `X-Client-Id` + `X-Client-Secret` on every call.
 *  - Amounts are decimal reais, not cents, in the request and in every answer.
 *  - Idempotent by `external_id`: "the same external_id returns the same
 *    charge, not a second one". Our reference goes out as `external_id`, so a
 *    create retried after a timeout cannot become a second QR.
 *  - The webhook URL travels with each charge (`webhook_url`); there is
 *    nothing to register on the account, and the delivery carries no
 *    signature. Like every gateway here it is only a pointer — the charge is
 *    read back with the workspace's own key before anything changes.
 *  - A Pix lasts exactly 24 hours. There is no field to shorten it and no
 *    route to cancel one, so a deadline shorter than that would be a lie: the
 *    customer told "the time is up" could still pay for the rest of the day,
 *    and the money would land as "paid late" on a flow that already took the
 *    other branch. The node's deadline is therefore held to the QR's own
 *    lifetime (IntegrationProvider::chargeWindowMinutes), and the builder says
 *    so before the flow is saved.
 *
 * Only Pix (BRL) is implemented. XPag also issues SPEI, OXXO and hosted
 * checkouts in other currencies, but those answers have a different shape and
 * none of it was needed by a payment node in Brazil.
 */
class XPagGateway implements PaymentGateway
{
    use CallsProvider;

    public const BASE_URL = 'https://api.xpag.global';

    public const SANDBOX_CLIENT_PREFIX = 'xpagsandbox_';

    /** How long XPag keeps a dynamic Pix payable. Not configurable on their side. */
    public const PIX_LIFETIME_MINUTES = 1440;

    /**
     * Their words for a charge that ended. `confirmed` is the documented one;
     * the others are what the same field says on cash-out and in the sandbox's
     * simulated outcomes, accepted so a wording change on their side cannot
     * leave a paid charge pending.
     */
    private const PAID = ['confirmed', 'paid', 'completed', 'approved'];

    private const FAILED = ['failed', 'canceled', 'cancelled', 'refunded', 'rejected'];

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders([
                'X-Client-Id' => (string) $this->integration->credential('client_id'),
                'X-Client-Secret' => (string) $this->integration->credential('client_secret'),
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(8);
    }

    /**
     * `{ok: false, error, error_code}`, with the code also in the
     * X-App-Error-Code header. The code is folded into the text too: for
     * 401/403/429 CallsProvider swaps it for the status, and a 403 here is
     * either a disabled key or a key missing one permission — two different
     * fixes the dictionary can only tell apart by the code.
     */
    protected function errorFrom(Response $response): array
    {
        $message = $response->json('error');
        $code = $response->json('error_code') ?? $response->header('X-App-Error-Code');
        $code = is_string($code) && $code !== '' ? $code : null;

        $text = trim(implode(' ', array_filter([
            is_string($message) ? $message : null,
            $code !== null ? "[{$code}]" : null,
        ])));

        return [$text !== '' ? $text : null, $code];
    }

    private function isSandbox(): bool
    {
        return str_starts_with((string) $this->integration->credential('client_id'), self::SANDBOX_CLIENT_PREFIX);
    }

    public function verify(): array
    {
        // XPag's own advice: the balance route is the cheapest to test with.
        // Permissions are per key, though, and cash-in (what we need) is
        // separate from balance — so a 403 for the missing balance permission
        // still proves the Client ID and secret are right. A disabled key is a
        // 403 of its own and is not let through.
        try {
            $this->send(fn (PendingRequest $http) => $http->get('/balance'), 'verify');
        } catch (UpstreamServiceException $e) {
            if ($e->errorCode !== 'integration_permission_missing') {
                throw $e;
            }
        }

        return [
            'environment' => $this->isSandbox() ? 'sandbox' : 'production',
        ];
    }

    public function createCharge(ChargeRequest $charge): ChargeResult
    {
        if (strtoupper($charge->currency) !== 'BRL') {
            // Sending another currency would not fail: XPag would answer with
            // that country's rail (a CLABE, a checkout page) and no Pix code,
            // after creating a charge we would then refuse to send.
            throw UpstreamError::exception(
                IntegrationProvider::XPag->upstream(),
                "Pix is only issued in BRL, the charge is in {$charge->currency}",
                'pix_requires_brl',
                422,
                ['integration_id' => $this->integration->id, 'tenant_id' => $this->integration->tenant_id],
            );
        }

        $document = $charge->payerDocument ? preg_replace('/\D+/', '', $charge->payerDocument) : '';

        $body = array_filter([
            'currency' => 'BRL',
            // Its presence is what makes the charge dynamic and single-use;
            // without it XPag issues a static key that takes any amount.
            'amount' => round($charge->amountCents / 100, 2),
            'description' => Str::limit($charge->description, 140, ''),
            'external_id' => $charge->reference,
            'webhook_url' => $this->notificationUrl($charge),
            'name' => $charge->payerName ? Str::limit($charge->payerName, 100, '') : null,
            'document' => in_array(strlen((string) $document), [11, 14], true) ? $document : null,
        ], fn ($value) => $value !== null && $value !== '');

        $json = $this->send(fn (PendingRequest $http) => $http->post('/cashin', $body), 'create_charge');
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;

        $status = isset($data['status']) ? self::statusFrom((string) $data['status']) : FlowPaymentStatus::Pending;

        return new ChargeResult(
            providerPaymentId: (string) ($data['transaction_id'] ?? $data['request_number'] ?? ''),
            status: $status,
            pixCode: $this->pixCode($data),
            paymentUrl: is_string($data['checkout_url'] ?? null) ? $data['checkout_url'] : null,
            expiresAt: $this->expiresAt($data),
        );
    }

    public function fetchStatus(FlowPayment $payment): ChargeStatus
    {
        // Their id when the create answered; ours otherwise — a create that
        // timed out after XPag accepted it is still findable by external_id.
        $query = $payment->provider_payment_id
            ? ['transaction_id' => $payment->provider_payment_id]
            : ['external_id' => $payment->reference, 'limit' => 20];

        $response = null;

        try {
            $json = $this->send(function (PendingRequest $http) use ($query, &$response) {
                return $response = $http->get('/consult-transaction', $query);
            }, 'fetch_charge');
        } catch (UpstreamServiceException $e) {
            // Nothing there yet (the create never landed) is not an error to
            // retry forever: the charge is simply not paid, and the deadline
            // decides.
            if ($response instanceof Response && $response->status() === 404) {
                return new ChargeStatus(FlowPaymentStatus::Pending, providerStatus: 'not_found');
            }

            throw $e;
        }

        // By external_id XPag may answer with a list (it does for static
        // keys); by transaction_id, with the charge itself.
        $records = is_array($json['payments'] ?? null)
            ? array_values(array_filter($json['payments'], 'is_array'))
            : [$json];

        foreach ($records as $record) {
            $raw = strtolower((string) ($record['status'] ?? ''));

            if (in_array($raw, self::PAID, true)) {
                return new ChargeStatus(
                    status: FlowPaymentStatus::Paid,
                    // Read now, not from `updated_at`: XPag writes it without
                    // a zone ("2025-11-24 12:00:00") and does not say which,
                    // and a guess three hours off is how a Pix once looked
                    // paid before it was created (see AppTime).
                    paidAt: CarbonImmutable::now(),
                    providerStatus: $raw,
                    providerPaymentId: isset($record['transaction_id']) ? (string) $record['transaction_id'] : null,
                );
            }
        }

        $latest = $records[0] ?? [];
        $raw = strtolower((string) ($latest['status'] ?? 'pending'));

        return new ChargeStatus(
            status: self::statusFrom($raw),
            providerStatus: $raw,
            providerPaymentId: isset($latest['transaction_id']) ? (string) $latest['transaction_id'] : null,
        );
    }

    public function webhookReferences(Request $request): array
    {
        // Cash-out notices reach the same URL only if somebody points their
        // withdrawals at it; nothing of ours is ever a cash-out.
        $type = $request->input('type');

        if (is_string($type) && $type !== '' && $type !== 'cashin') {
            return [];
        }

        $reference = $request->input('external_id');

        return is_string($reference) && $reference !== '' ? [$reference] : [];
    }

    /**
     * A call, and the 200 that is not a success: XPag's body always says
     * `ok`, and a false one is a refusal whatever the status line says.
     *
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call, string $action): array
    {
        $json = $this->call($call, $action);

        if (($json['ok'] ?? null) === false) {
            $code = is_string($json['error_code'] ?? null) ? $json['error_code'] : null;

            throw UpstreamError::exception(
                IntegrationProvider::XPag->upstream(),
                trim(((string) ($json['error'] ?? 'ok=false')).($code ? " [{$code}]" : '')),
                $code,
                422,
                ['integration_id' => $this->integration->id, 'tenant_id' => $this->integration->tenant_id, 'action' => $action],
            );
        }

        return $json;
    }

    /**
     * The copy-and-paste code. `copyPaste` is what the BRL docs show;
     * `copy_paste` is the spelling everywhere else in the same API, and `code`
     * carries the same EMV string — accepted only when it looks like one.
     *
     * @param  array<string, mixed>  $data
     */
    private function pixCode(array $data): ?string
    {
        foreach (['copyPaste', 'copy_paste', 'code'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && str_starts_with($value, '000201')) {
                return $value;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    private function expiresAt(array $data): CarbonImmutable
    {
        if (is_string($data['expires_at'] ?? null) && $data['expires_at'] !== '') {
            try {
                return CarbonImmutable::parse($data['expires_at']);
            } catch (\Throwable) {
                // Fall through to the documented lifetime.
            }
        }

        $minutes = is_numeric($data['expire_minutes'] ?? null)
            ? (int) $data['expire_minutes']
            : self::PIX_LIFETIME_MINUTES;

        // From now, after XPag answered: a hair later than their own clock,
        // which is the safe side — their expiry fires first, and the read-back
        // at our deadline then finds the charge already closed.
        return CarbonImmutable::now()->addMinutes(max(1, $minutes));
    }

    /**
     * Only an https address is sent. A delivery to the plain-http address of a
     * development machine reaches nobody, and the polling sweep confirms the
     * charge either way.
     */
    private function notificationUrl(ChargeRequest $charge): ?string
    {
        return $charge->notificationUrl && str_starts_with($charge->notificationUrl, 'https://')
            ? $charge->notificationUrl
            : null;
    }

    private static function statusFrom(string $status): FlowPaymentStatus
    {
        $status = strtolower($status);

        return match (true) {
            in_array($status, self::PAID, true) => FlowPaymentStatus::Paid,
            $status === 'expired' => FlowPaymentStatus::Expired,
            in_array($status, self::FAILED, true) => FlowPaymentStatus::Failed,
            // `pending`, and the dispute states (`med`, `med_closed`) that only
            // follow a payment — the deadline decides those.
            default => FlowPaymentStatus::Pending,
        };
    }
}
