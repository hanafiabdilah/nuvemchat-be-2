<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpUrls;
use App\Services\Mcp\Scopes;
use Illuminate\Http\JsonResponse;

/**
 * The two discovery documents that let a client that knows nothing but the MCP
 * endpoint find its way to a token.
 *
 * Both are public and both are cacheable — they describe the server, not the
 * caller. A client reads the protected-resource document to learn which
 * authorization server to talk to, then the authorization-server document to
 * learn where its endpoints are.
 */
class MetadataController extends Controller
{
    /**
     * RFC 9728. Advertised two ways, because clients look in two places: this
     * document at a well-known path, and the `resource_metadata` parameter on
     * the 401 challenge (see AuthenticateMcp).
     */
    public function protectedResource(): JsonResponse
    {
        return $this->cacheable([
            'resource' => McpUrls::resource(),
            'authorization_servers' => [McpUrls::issuer()],
            // The minimum a client needs to be useful here. Write access is
            // requested on top, per the specification's advice that this field
            // be the smallest workable set rather than everything on offer.
            'scopes_supported' => Scopes::all(),
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => McpUrls::issuer().'/developer',
        ]);
    }

    /** RFC 8414. */
    public function authorizationServer(): JsonResponse
    {
        return $this->cacheable([
            'issuer' => McpUrls::issuer(),
            'authorization_endpoint' => McpUrls::authorizationEndpoint(),
            'token_endpoint' => McpUrls::tokenEndpoint(),
            'registration_endpoint' => McpUrls::registrationEndpoint(),
            'revocation_endpoint' => McpUrls::revocationEndpoint(),
            'scopes_supported' => Scopes::all(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // S256 only. OAuth 2.1 removed `plain`, and a downgrade to it is
            // one of the things PKCE exists to prevent.
            'code_challenge_methods_supported' => ['S256'],
            // Public clients only: an editor on somebody's laptop cannot keep a
            // secret, and pretending otherwise buys nothing.
            'token_endpoint_auth_methods_supported' => ['none'],
            // RFC 9207: we return `iss` on the authorization response, and
            // saying so here is what makes a client insist on seeing it.
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    private function cacheable(array $document): JsonResponse
    {
        return response()->json($document)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
