<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\OAuth\ClientRegistrar;
use App\Services\Mcp\OAuth\ClientRegistrationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RFC 7591 Dynamic Client Registration.
 *
 * Open, and that is correct rather than an oversight: a client_id here is
 * public, confers nothing, and only becomes anything at all once a person reads
 * a consent screen and approves it. What stops abuse is the rate limit, not a
 * credential.
 *
 * The specification now prefers Client ID Metadata Documents and marks this
 * deprecated, but most shipping clients still register this way and refusing
 * them would be a purity nobody asked for. Both work; see ClientRegistrar.
 */
class RegistrationController extends Controller
{
    public function __construct(
        private ClientRegistrar $registrar,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $client = $this->registrar->register($request->all());
        } catch (ClientRegistrationException $e) {
            // The words here are ours and the reader is whoever is building the
            // client, so the message goes through as-is — the one place on this
            // platform where that is the right call, because there is no
            // customer in front of this endpoint.
            return response()->json([
                'error' => 'invalid_client_metadata',
                'error_description' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }
}
