<?php

namespace App\Services\Billing\Fiscal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Plugnotas (TecnoSpeed) NFS-e API, on Pingly's own account.
 *
 * What Plugnotas is like, and how each part shaped this class (contract read
 * from their OpenAPI document, docs.plugnotas.com.br/api.json):
 *
 *  - One `x-api-key` per account; sandbox and production are different hosts
 *    and a key from one is refused by the other.
 *  - `POST /nfse` takes an *array* of notas and answers at once with an id and
 *    a protocol — accepting is not issuing. The prefeitura's answer comes later
 *    as `situacao` CONCLUIDO, REJEITADO, DENEGADO or CANCELADO, read back from
 *    `GET /nfse/consultar/{idIntegracao}/{cnpj}` or pushed to the webhook.
 *  - `idIntegracao` is unique per company: sending it twice answers 409
 *    ("Já existe um(a) NFSe…"). That is what makes a retried submission safe —
 *    the second one is refused and the first is adopted.
 *  - The PDF and XML sit behind the API key, so a tenant never gets a
 *    Plugnotas link — the dashboard downloads through us.
 *  - The webhook is registered per company (`/empresa/{cnpj}/webhook`) with
 *    custom headers, which is where our token travels.
 */
class PlugnotasClient
{
    public function issue(array $nota): array
    {
        $json = $this->send(fn (PendingRequest $http) => $http->post('/nfse', [$nota]));

        $document = $json['documents'][0] ?? [];

        return [
            'id' => isset($document['id']) ? (string) $document['id'] : null,
            'protocol' => isset($json['protocol']) ? (string) $json['protocol'] : null,
            'message' => $json['message'] ?? null,
        ];
    }

    /**
     * The nota's current state, or null while Plugnotas has nothing to show
     * for this reference yet.
     */
    public function summary(string $reference, string $cnpj): ?array
    {
        try {
            $json = $this->send(fn (PendingRequest $http) => $http->get('/nfse/consultar/'.rawurlencode($reference).'/'.$cnpj));
        } catch (PlugnotasException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        // An array of every nota sent under this reference; the newest is the
        // one that matters (a cancelled one and its replacement can coexist).
        $rows = array_values(array_filter(is_array($json) && array_is_list($json) ? $json : [$json], 'is_array'));

        return $rows === [] ? null : end($rows);
    }

    public function cancel(string $id, ?string $reason = null): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post('/nfse/cancelar/'.rawurlencode($id), array_filter([
            'motivo' => $reason,
        ])));
    }

    /** Raw bytes; `$kind` is pdf or xml. */
    public function document(string $id, string $kind = 'pdf'): Response
    {
        $response = $this->request()
            ->accept($kind === 'xml' ? 'application/xml' : 'application/pdf')
            ->timeout(40)
            ->get("/nfse/{$kind}/".rawurlencode($id));

        if ($response->failed()) {
            [$message] = $this->errorFrom($response);

            throw new PlugnotasException($message ?? "HTTP {$response->status()}", $response->status());
        }

        return $response;
    }

    public function company(string $cnpj): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/empresa/'.$cnpj));
    }

    /**
     * Point the company's webhook at us. PUT when one exists (Plugnotas keeps
     * one per company), POST otherwise.
     */
    public function registerWebhook(string $cnpj, string $url, string $token): void
    {
        $body = [
            'url' => $url,
            'method' => 'POST',
            'headers' => [PlugnotasConfig::WEBHOOK_HEADER => $token],
        ];

        $exists = true;

        try {
            $current = $this->send(fn (PendingRequest $http) => $http->get("/empresa/{$cnpj}/webhook"));
            $exists = is_array($current) && filled($current['url'] ?? null);
        } catch (PlugnotasException $e) {
            if ($e->isTransport()) {
                throw $e;
            }

            $exists = false;
        }

        $this->send(fn (PendingRequest $http) => $exists
            ? $http->put("/empresa/{$cnpj}/webhook", $body)
            : $http->post("/empresa/{$cnpj}/webhook", $body));
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(PlugnotasConfig::baseUrl())
            ->withHeaders(['x-api-key' => (string) PlugnotasConfig::apiKey()])
            ->acceptJson()
            ->asJson()
            ->timeout(25)
            ->connectTimeout(8);
    }

    protected function send(callable $call): array
    {
        if (PlugnotasConfig::apiKey() === null) {
            throw new PlugnotasException('Plugnotas API key is not configured.', 422);
        }

        try {
            /** @var Response $response */
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            throw new PlugnotasException($e->getMessage(), 0, null, $e);
        }

        if ($response->failed()) {
            [$message, $data] = $this->errorFrom($response);

            throw new PlugnotasException($message ?? "HTTP {$response->status()}", $response->status(), $data);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Plugnotas wraps errors as `{error: {message, data}}`; the field-level
     * detail of a schema failure is in `data.fields`, which is the part that
     * actually names the problem — so it is folded into the message.
     */
    protected function errorFrom(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            return [null, null];
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : $json;
        $message = is_string($error['message'] ?? null) ? $error['message'] : null;
        $data = $error['data'] ?? null;

        $fields = is_array($data) ? ($data['fields'] ?? null) : null;

        if (is_array($fields) && $fields !== []) {
            $parts = [];

            foreach ($fields as $field => $problem) {
                $parts[] = is_string($field) ? "{$field}: ".(is_scalar($problem) ? $problem : json_encode($problem)) : (is_scalar($problem) ? (string) $problem : json_encode($problem));
            }

            $message = trim(($message ? $message.' — ' : '').implode('; ', $parts));
        }

        return [$message, $data];
    }
}
