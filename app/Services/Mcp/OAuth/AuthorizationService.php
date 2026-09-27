<?php

namespace App\Services\Mcp\OAuth;

use App\Models\AuditLog;
use App\Models\McpAuthorizationCode;
use App\Models\McpClient;
use App\Models\McpConnection;
use App\Models\McpToken;
use App\Models\User;
use App\Services\Mcp\McpUrls;
use App\Services\Mcp\Scopes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The authorization-code half of the MCP OAuth server.
 *
 * Only one grant type is offered, `authorization_code` with PKCE, plus its
 * `refresh_token`. No implicit, no password, no client credentials — OAuth 2.1
 * removed the first two and the third would mean a credential that acts for the
 * workspace with nobody's permissions behind it, which is the thing this whole
 * surface exists to avoid.
 */
final class AuthorizationService
{
    /**
     * Mint a code and return the URL the browser should be sent to.
     *
     * The caller has already authenticated the person and shown them what they
     * are approving; this is the part that has to be encoded in exactly one
     * place, because `state` and `iss` are how the client detects a response
     * that did not come from us.
     */
    public function issueCode(
        User $user,
        McpClient $client,
        array $scopes,
        string $redirectUri,
        string $codeChallenge,
        ?string $resource,
        ?string $state,
    ): string {
        $this->assertRoomForAnotherConnection($user, $client);

        $plain = Str::random(64);

        McpAuthorizationCode::create([
            'code_hash' => McpAuthorizationCode::hash($plain),
            'mcp_client_id' => $client->id,
            'user_id' => $user->id,
            'scopes' => $scopes,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'resource' => $resource,
            'expires_at' => now()->addSeconds((int) config('mcp.authorization_code_ttl')),
        ]);

        return $this->redirect($redirectUri, ['code' => $plain] + $this->stateAndIssuer($state));
    }

    /** The person said no. Same shape, an error instead of a code. */
    public function denial(string $redirectUri, ?string $state, string $error = 'access_denied'): string
    {
        return $this->redirect($redirectUri, ['error' => $error] + $this->stateAndIssuer($state));
    }

    /**
     * Exchange a code for tokens.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws OAuthException
     */
    public function exchange(array $input, ClientRegistrar $registrar): array
    {
        $clientId = $this->required($input, 'client_id');
        $codeValue = $this->required($input, 'code');
        $verifier = $this->required($input, 'code_verifier');
        $redirectUri = $this->required($input, 'redirect_uri');
        $resource = $this->optional($input, 'resource');

        $client = $registrar->resolve($clientId)
            ?? throw OAuthException::invalidClient('Unknown client_id.');

        /** @var McpAuthorizationCode|null $code */
        $code = DB::transaction(function () use ($codeValue) {
            $row = McpAuthorizationCode::where('code_hash', McpAuthorizationCode::hash($codeValue))
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return null;
            }

            // ⚠️ A code that has already been spent is not simply refused. OAuth
            // 2.1 asks the server to assume the worst — somebody replayed a code
            // out of a browser history or a proxy log — and take down everything
            // that code produced. The legitimate client loses a session it can
            // rebuild in one redirect; a thief loses the tokens.
            if ($row->consumed_at !== null) {
                $this->revokeEverythingFrom($row);

                return null;
            }

            $row->forceFill(['consumed_at' => now()])->save();

            return $row;
        });

        if (! $code || ! $code->expires_at->isFuture()) {
            throw OAuthException::invalidGrant('This authorization code is not valid any more. Start the authorization again.');
        }

        if ($code->mcp_client_id !== $client->id) {
            throw OAuthException::invalidGrant('This authorization code was issued to another client.');
        }

        // Exact match, the same rule the registration applied. The redirect URI
        // is part of what the person approved.
        if (! hash_equals($code->redirect_uri, $redirectUri)) {
            throw OAuthException::invalidGrant('The redirect_uri does not match the one this code was issued for.');
        }

        if (! McpUrls::isOwnResource($resource) || ! $this->resourceMatches($code->resource, $resource)) {
            throw OAuthException::invalidGrant('The resource does not match the one this code was issued for.');
        }

        $this->verifyPkce($code->code_challenge, $verifier);

        $user = $code->user;

        if (! $user || ! $user->tenant_id) {
            throw OAuthException::invalidGrant('The account behind this authorization is no longer available.');
        }

        $connection = $this->grantConnection($user, $client, $code->scopeList());

        return $this->issueTokens($connection);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws OAuthException
     */
    public function refresh(array $input, ClientRegistrar $registrar): array
    {
        $clientId = $this->required($input, 'client_id');
        $presented = $this->required($input, 'refresh_token');
        $resource = $this->optional($input, 'resource');

        if (! McpUrls::isOwnResource($resource)) {
            throw OAuthException::invalidGrant('The resource does not name this server.');
        }

        $client = $registrar->resolve($clientId)
            ?? throw OAuthException::invalidClient('Unknown client_id.');

        /** @var McpToken|null $token */
        $token = McpToken::with('connection')
            ->where('token_hash', McpToken::hash($presented))
            ->where('type', McpToken::TYPE_REFRESH)
            ->first();

        if (! $token) {
            throw OAuthException::invalidGrant('This refresh token is not valid.');
        }

        // ⚠️ Rotation means a refresh token is used exactly once. Seeing a
        // second use of one that already has a successor is not a retry — the
        // successor went somewhere, and one of the two holders is not the
        // client. The whole connection goes, and the person is told by the
        // thing simply stopping and asking to be connected again.
        if ($token->replaced_by_id !== null || ! $token->isUsable()) {
            if ($token->replaced_by_id !== null && $token->connection) {
                Log::warning('MCP: a rotated refresh token was presented again, revoking the connection', [
                    'mcp_connection_id' => $token->mcp_connection_id,
                    'tenant_id' => $token->connection->tenant_id,
                ]);

                $token->connection->revoke();
            }

            throw OAuthException::invalidGrant('This refresh token is not valid.');
        }

        $connection = $token->connection;

        if (! $connection || ! $connection->isActive() || $connection->mcp_client_id !== $client->id) {
            throw OAuthException::invalidGrant('This refresh token is not valid.');
        }

        $issued = $this->issueTokens($connection);

        $token->forceFill([
            'revoked_at' => now(),
            'replaced_by_id' => $issued['_refresh_id'],
        ])->save();

        unset($issued['_refresh_id']);

        return $issued;
    }

    /**
     * RFC 7009. Revoking an access token drops that token; revoking a refresh
     * token drops the connection, because that is what a client means when it
     * says "forget me" — leaving the access token alive for its last hour would
     * be a surprise nobody asked for.
     */
    public function revoke(string $presented): void
    {
        $token = McpToken::with('connection')->where('token_hash', McpToken::hash($presented))->first();

        if (! $token) {
            return;
        }

        if ($token->type === McpToken::TYPE_REFRESH) {
            $token->connection?->revoke();

            return;
        }

        $token->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * One connection per person per client, reused on re-authorization.
     *
     * Scopes are replaced rather than merged: the consent screen the person
     * just read listed a set, and that set is the decision. Merging would mean
     * a connection quietly keeps a permission the person did not approve this
     * time and has no way to see they still hold.
     */
    private function grantConnection(User $user, McpClient $client, array $scopes): McpConnection
    {
        $connection = McpConnection::active()
            ->where('user_id', $user->id)
            ->where('mcp_client_id', $client->id)
            ->first();

        if ($connection) {
            $connection->update(['scopes' => $scopes, 'client_name' => $client->client_name]);
        } else {
            $connection = McpConnection::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'mcp_client_id' => $client->id,
                'client_name' => $client->client_name,
                'scopes' => $scopes,
            ]);
        }

        AuditLog::record(
            'mcp.connection.granted',
            "Connected \"{$client->client_name}\" to workspace #{$user->tenant_id}",
            [
                'tenant_id' => $user->tenant_id,
                'mcp_connection_id' => $connection->id,
                'client_id' => $client->client_id,
                'scopes' => $scopes,
            ],
            $user,
        );

        return $connection;
    }

    /** @return array<string, mixed> */
    private function issueTokens(McpConnection $connection): array
    {
        $accessPlain = McpToken::mint(McpToken::TYPE_ACCESS);
        $refreshPlain = McpToken::mint(McpToken::TYPE_REFRESH);

        $ttl = (int) config('mcp.access_token_ttl_minutes');

        McpToken::create([
            'token_hash' => McpToken::hash($accessPlain),
            'mcp_connection_id' => $connection->id,
            'type' => McpToken::TYPE_ACCESS,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $refresh = McpToken::create([
            'token_hash' => McpToken::hash($refreshPlain),
            'mcp_connection_id' => $connection->id,
            'type' => McpToken::TYPE_REFRESH,
            'expires_at' => now()->addDays((int) config('mcp.refresh_token_ttl_days')),
        ]);

        return [
            'access_token' => $accessPlain,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * 60,
            'refresh_token' => $refreshPlain,
            'scope' => Scopes::toHeader($connection->scopeList()),
            '_refresh_id' => $refresh->id,
        ];
    }

    private function verifyPkce(string $challenge, string $verifier): void
    {
        // RFC 7636 §4.1. A short verifier is a client that generated something
        // guessable, which defeats the point of the exchange.
        $length = strlen($verifier);

        if ($length < 43 || $length > 128) {
            throw OAuthException::invalidGrant('The code_verifier is not the right length.');
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (! hash_equals($challenge, $expected)) {
            throw OAuthException::invalidGrant('The code_verifier does not match the challenge.');
        }
    }

    private function resourceMatches(?string $granted, ?string $presented): bool
    {
        if ($granted === null || $presented === null) {
            return true;
        }

        return rtrim(strtolower($granted), '/') === rtrim(strtolower($presented), '/');
    }

    private function revokeEverythingFrom(McpAuthorizationCode $code): void
    {
        McpConnection::active()
            ->where('user_id', $code->user_id)
            ->where('mcp_client_id', $code->mcp_client_id)
            ->get()
            ->each(fn (McpConnection $connection) => $connection->revoke());

        Log::warning('MCP: an authorization code was presented twice, revoking what it produced', [
            'mcp_client_id' => $code->mcp_client_id,
            'user_id' => $code->user_id,
        ]);
    }

    private function assertRoomForAnotherConnection(User $user, McpClient $client): void
    {
        $existing = McpConnection::active()
            ->where('user_id', $user->id)
            ->where('mcp_client_id', '!=', $client->id)
            ->count();

        if ($existing >= (int) config('mcp.max_connections_per_user')) {
            throw OAuthException::invalidRequest(
                'You have reached the maximum number of connected apps. Disconnect one in Developer › MCP first.'
            );
        }
    }

    /** @return array<string, string> */
    private function stateAndIssuer(?string $state): array
    {
        // RFC 9207. A client that recorded which issuer it sent the person to
        // compares this before it does anything with the code, which is what
        // stops a mix-up attack between two authorization servers.
        $params = ['iss' => McpUrls::issuer()];

        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }

        return $params;
    }

    private function redirect(string $redirectUri, array $params): string
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri.$separator.http_build_query($params);
    }

    private function required(array $input, string $key): string
    {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            throw OAuthException::invalidRequest("The {$key} parameter is required.");
        }

        return $value;
    }

    private function optional(array $input, string $key): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
