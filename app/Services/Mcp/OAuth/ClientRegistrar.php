<?php

namespace App\Services\Mcp\OAuth;

use App\Models\McpClient;
use App\Support\OutboundHttp;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * How an MCP client gets a client_id.
 *
 * Two ways, because the ecosystem is mid-migration. Client ID Metadata
 * Documents (CIMD) is what the specification now prefers: the client_id *is* an
 * https URL and we read the client's metadata from it. Dynamic Client
 * Registration (RFC 7591) is marked deprecated but is what most shipping
 * clients still do, and dropping it would refuse them for a purity nobody asked
 * for.
 *
 * Every client here is *public* — no secret. That is not a shortcut: these are
 * editors on people's laptops, a secret compiled into one is a secret published
 * to everyone who has it, and OAuth 2.1 says so. What proves the caller at the
 * token endpoint is PKCE.
 */
final class ClientRegistrar
{
    /** Loopback hosts, the only ones allowed to redirect over plain http (RFC 8252 §7.3). */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    private const MAX_REDIRECT_URIS = 10;

    /**
     * RFC 7591. Anyone may call it — that is what "dynamic" means, and there is
     * nothing to protect: a client_id is public, carries no rights, and only
     * becomes anything once a person approves it on the consent screen.
     *
     * @param  array{client_name?: string, redirect_uris: list<string>, client_uri?: string}  $metadata
     *
     * @throws ClientRegistrationException
     */
    public function register(array $metadata): McpClient
    {
        $redirects = $this->validRedirects($metadata['redirect_uris'] ?? []);

        return McpClient::create([
            'client_id' => 'mcpc_'.Str::random(40),
            'client_name' => $this->name($metadata),
            'source' => McpClient::SOURCE_DCR,
            'redirect_uris' => $redirects,
            'client_uri' => $this->httpsOrNull($metadata['client_uri'] ?? null),
        ]);
    }

    /**
     * Resolve a client_id that arrived on an authorization request.
     *
     * A known id is returned as-is (its CIMD document refreshed if stale). An
     * unknown one is only a client if it is an https URL — that is the CIMD
     * shape, and it is the only case where an id we never issued can mean
     * anything.
     *
     * @throws ClientRegistrationException
     */
    public function resolve(string $clientId): ?McpClient
    {
        $client = McpClient::where('client_id', $clientId)->first();

        if ($client && ! $client->metadataIsStale()) {
            return $client;
        }

        if (! $this->looksLikeMetadataUrl($clientId)) {
            return $client;
        }

        if (! config('mcp.client_id_metadata_documents')) {
            return $client;
        }

        try {
            return $this->fromMetadataDocument($clientId, $client);
        } catch (ClientRegistrationException $e) {
            // A stale document that will not re-read is not a reason to lock
            // out a client that already registered and already works; the copy
            // we hold is the last one it published.
            if ($client) {
                Log::warning('MCP: could not refresh a client metadata document', [
                    'client_id' => $clientId,
                    'reason' => $e->getMessage(),
                ]);

                return $client;
            }

            throw $e;
        }
    }

    /**
     * Fetch and trust an https client_id (CIMD).
     *
     * ⚠️ This is an outbound request to an address the caller chose, so it goes
     * through the same guard as every other one — `PublicUrl::isFetchable`
     * before the call and `OutboundHttp::guard` for the redirects, or a
     * client_id of `http://169.254.169.254/` would make this endpoint a
     * metadata-service reader for anyone who can reach it.
     *
     * @throws ClientRegistrationException
     */
    private function fromMetadataDocument(string $url, ?McpClient $existing): McpClient
    {
        if (! PublicUrl::isFetchable($url)) {
            throw new ClientRegistrationException('The client_id URL does not point at a public address.');
        }

        $maxBytes = (int) config('mcp.client_metadata_max_bytes');

        $response = OutboundHttp::guard(
            OutboundHttp::pinHost(Http::timeout(8)->accept('application/json'), $url),
            $maxBytes,
        )->get($url);

        if ($response->failed()) {
            throw new ClientRegistrationException('The client_id URL did not return its metadata ('.$response->status().').');
        }

        $document = $response->json();

        if (! is_array($document)) {
            throw new ClientRegistrationException('The client_id URL did not return a JSON object.');
        }

        // The document describes itself, so it has to agree with where it was
        // found — otherwise one published document could claim to be any client.
        if (isset($document['client_id']) && $document['client_id'] !== $url) {
            throw new ClientRegistrationException('The metadata document names a different client_id.');
        }

        $redirects = $this->validRedirects($document['redirect_uris'] ?? [], $url);

        $attributes = [
            'client_name' => $this->name($document),
            'source' => McpClient::SOURCE_CIMD,
            'redirect_uris' => $redirects,
            'client_uri' => $this->httpsOrNull($document['client_uri'] ?? null),
            'metadata_fetched_at' => now(),
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return McpClient::create($attributes + ['client_id' => $url]);
    }

    /**
     * @param  string|null  $origin  when set (CIMD), redirects must share this document's origin
     * @return list<string>
     *
     * @throws ClientRegistrationException
     */
    private function validRedirects(mixed $uris, ?string $origin = null): array
    {
        if (! is_array($uris) || $uris === []) {
            throw new ClientRegistrationException('At least one redirect_uri is required.');
        }

        if (count($uris) > self::MAX_REDIRECT_URIS) {
            throw new ClientRegistrationException('Too many redirect URIs.');
        }

        $valid = [];

        foreach ($uris as $uri) {
            if (! is_string($uri) || $uri === '' || str_contains($uri, '#')) {
                throw new ClientRegistrationException('Each redirect_uri must be an absolute URI without a fragment.');
            }

            if (! $this->redirectAllowed($uri, $origin)) {
                throw new ClientRegistrationException('This redirect_uri is not allowed: '.$uri);
            }

            $valid[] = $uri;
        }

        return array_values(array_unique($valid));
    }

    /**
     * https anywhere, http only on loopback, and private-use schemes for
     * desktop apps (RFC 8252). Nothing else — a `javascript:` or `data:`
     * redirect is a way to run script in whatever context opened the browser.
     */
    private function redirectAllowed(string $uri, ?string $origin): bool
    {
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        if ($scheme === 'http') {
            // Loopback keeps its port free: a native client binds whatever port
            // is available, and it registers the one it got.
            return in_array($host, self::LOOPBACK_HOSTS, true);
        }

        if ($scheme === 'https') {
            // ⚠️ CIMD only: a document published at example.com may not send
            // people to somebody else's domain. Without this, a client_id URL
            // is a redirector anyone can point anywhere.
            return $origin === null || $this->sameOrigin($uri, $origin) || in_array($host, self::LOOPBACK_HOSTS, true);
        }

        // A private-use scheme, which RFC 8252 says should be a domain the app
        // controls, reversed: `com.example.app:/oauth`. The dot is what
        // distinguishes it from `javascript`, `data`, `file` and friends.
        return $scheme !== '' && str_contains($scheme, '.');
    }

    private function sameOrigin(string $a, string $b): bool
    {
        $partsOf = static fn (string $url): string => strtolower(
            (string) parse_url($url, PHP_URL_SCHEME).'://'
            .(string) parse_url($url, PHP_URL_HOST)
            .':'.(string) (parse_url($url, PHP_URL_PORT) ?: '')
        );

        return $partsOf($a) === $partsOf($b);
    }

    private function looksLikeMetadataUrl(string $clientId): bool
    {
        return str_starts_with(strtolower($clientId), 'https://');
    }

    private function name(array $metadata): string
    {
        $name = trim((string) ($metadata['client_name'] ?? ''));

        // The name is what the person reads on the consent screen, so it is
        // stripped of anything that could dress it up as something else.
        $name = preg_replace('/[\p{C}]+/u', '', $name) ?? '';

        return Str::limit($name !== '' ? $name : 'Unnamed MCP client', 120, '');
    }

    private function httpsOrNull(mixed $url): ?string
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://')
            ? Str::limit($url, 500, '')
            : null;
    }
}
