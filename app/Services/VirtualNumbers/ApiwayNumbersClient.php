<?php

namespace App\Services\VirtualNumbers;

use App\Exceptions\ApiwayNumbersException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the API Way numbers portal (`/api/*` on portal.apiway.com.br).
 *
 * Authenticated with a token generated in the portal and pasted into the Back
 * Office — the same arrangement as every other integration here. A 401 is
 * therefore a fact about the stored token, not a session that lapsed: there is
 * nothing to refresh and nothing to retry, so it surfaces as "the account
 * refused our token" and somebody pastes a new one.
 *
 * ⚠️ `POST /numbers` is never retried automatically. It takes no idempotency
 * key, so a retry after a timeout does not re-attempt the purchase — it buys a
 * second number, on a second monthly subscription, that nobody asked for. Every
 * other call here is a read or an idempotent delete and may retry freely.
 */
class ApiwayNumbersClient
{
    public function isConfigured(): bool
    {
        return ApiwayNumbersConfig::isConfigured();
    }

    // --- Catalog & numbers -------------------------------------------------

    /**
     * Apps on offer, DDD → city map, and the monthly cost per number.
     *
     * Doubles as the Back Office's "test connection": it is the cheapest call
     * that proves the token is accepted and that sales are enabled.
     *
     * @return array{apps: list<array{id: string, label: string}>, regions: array<string, string>, price_cents: int, currency: string}
     */
    public function catalog(): array
    {
        return $this->send('get', '/numbers/catalog');
    }

    /**
     * Contract a number. `partner_customer_id` is mandatory for reseller
     * accounts — without it the portal answers 422 — and is what lets API Way
     * trace a number back to the end customer when they have to.
     */
    public function createNumber(string $ddd, string $app, string $partnerCustomerId): array
    {
        return $this->send('post', '/numbers', [
            'ddd' => $ddd,
            'app' => $app,
            'partner_customer_id' => $partnerCustomerId,
        ], timeout: 60, retry: false);
    }

    /** Every number on the account — ours, across all tenants. */
    public function numbers(): array
    {
        return $this->send('get', '/numbers');
    }

    /** One number plus its most recent messages. */
    public function number(int $id): array
    {
        return $this->send('get', "/numbers/{$id}");
    }

    /** Messages received on a number, newest first. */
    public function sms(int $id): array
    {
        return $this->send('get', "/numbers/{$id}/sms");
    }

    /** Cancel a number and stop its monthly charge. Idempotent upstream. */
    public function cancelNumber(int $id): array
    {
        return $this->send('delete', "/numbers/{$id}", timeout: 60);
    }

    // --- Webhook (one per account, so: one for the whole platform) ----------

    /** @return array{url?: string, enabled?: bool, secret_set?: bool, secret_preview?: string} */
    public function getWebhook(): array
    {
        return $this->send('get', '/numbers/webhook');
    }

    /**
     * Register (or rotate) the platform's receive URL.
     *
     * The response carries the raw `secret`, and it is the only time it is ever
     * shown — the GET returns a preview forever after. Whoever calls this must
     * persist it in the same breath.
     */
    public function setWebhook(string $url, bool $rotate = false): array
    {
        return $this->send('put', '/numbers/webhook'.($rotate ? '?rotate=1' : ''), ['url' => $url]);
    }

    public function deleteWebhook(): void
    {
        $this->send('delete', '/numbers/webhook');
    }

    // --- Plumbing ----------------------------------------------------------

    protected function send(string $method, string $path, array $payload = [], int $timeout = 30, bool $retry = true): array
    {
        $token = ApiwayNumbersConfig::token();

        if (empty($token)) {
            throw new ApiwayNumbersException(
                'O token da API Way Números não está configurado.',
                ApiwayNumbersException::UNCONFIGURED,
                503,
            );
        }

        $request = $this->baseRequest($timeout)->withToken($token);

        if ($retry) {
            // Connection failures only: a refused TCP handshake never reached
            // the portal, so nothing upstream can have happened twice.
            $request = $request->retry(2, 1000, fn ($e) => $e instanceof HttpConnectionException, throw: false);
        }

        try {
            $response = match ($method) {
                'get' => $request->get($this->url($path), $payload),
                'post' => $request->post($this->url($path), $payload),
                'put' => $request->put($this->url($path), $payload),
                'delete' => $request->delete($this->url($path), $payload),
                default => throw new \InvalidArgumentException("Unsupported method {$method}."),
            };
        } catch (HttpConnectionException $e) {
            throw new ApiwayNumbersException(
                'Não foi possível falar com a API Way agora.',
                ApiwayNumbersException::UPSTREAM_UNAVAILABLE,
                0,
                previous: $e,
            );
        }

        return $this->decode($response);
    }

    protected function baseRequest(int $timeout = 30): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout(15)
            ->timeout($timeout);
    }

    protected function url(string $path): string
    {
        return ApiwayNumbersConfig::baseUrl().$path;
    }

    /**
     * Decode a response, raising a typed exception on anything but 2xx.
     *
     * Both shapes the portal uses are accepted: `/numbers` and `/numbers/{id}/sms`
     * answer with a bare JSON array, everything else with an object. A 204
     * (delete webhook) has no body at all.
     */
    protected function decode(Response $response): array
    {
        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $status = $response->status();
        $cap = isset($json['cap']) && is_array($json['cap']) ? $json['cap'] : null;

        [$code, $message] = match (true) {
            $status === 401 => [ApiwayNumbersException::UNAUTHENTICATED, 'A conta API Way recusou o token da plataforma.'],
            $status === 403 => [ApiwayNumbersException::SALES_DISABLED, 'A venda de números está desativada na conta API Way.'],
            $status === 404 => [ApiwayNumbersException::NOT_FOUND, 'Número não encontrado na conta API Way.'],
            $status === 422 && $cap !== null => [ApiwayNumbersException::CAP_REACHED, 'Não há números disponíveis no momento. Tente novamente em instantes.'],
            $status === 422 => [ApiwayNumbersException::INVALID_REQUEST, $json['message'] ?? 'Dados inválidos para contratar o número.'],
            default => [ApiwayNumbersException::UPSTREAM_UNAVAILABLE, 'A API Way está indisponível no momento.'],
        };

        // The upstream message is logged rather than shown for the codes above
        // where we substitute our own: "limite de números atingido" is true of
        // the platform's account and means nothing to the tenant reading it.
        Log::warning('API Way numbers request failed', [
            'status' => $status,
            'code' => $code,
            'upstream_message' => $json['message'] ?? null,
            'cap' => $cap,
        ]);

        throw new ApiwayNumbersException($message, $code, $status, $cap);
    }
}
