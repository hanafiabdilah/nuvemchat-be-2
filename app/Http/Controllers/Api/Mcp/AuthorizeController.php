<?php

namespace App\Http\Controllers\Api\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpClient;
use App\Services\Mcp\McpUrls;
use App\Services\Mcp\OAuth\AuthorizationService;
use App\Services\Mcp\OAuth\ClientRegistrar;
use App\Services\Mcp\OAuth\ClientRegistrationException;
use App\Services\Mcp\OAuth\OAuthException;
use App\Services\Mcp\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The consent screen's back end.
 *
 * ⚠️ The screen itself is a React route, not a Blade page, and this pair of
 * endpoints is why. The dashboard authenticates with a bearer token in
 * localStorage and sends no cookies, so a server-rendered page at
 * `/oauth/mcp/authorize` would have no session and no idea who was reading it.
 * The SPA reads the query string, asks `show()` who is asking for what, renders
 * it, and posts the person's answer with the token it already holds.
 *
 * The split also draws the line OAuth needs: a bad `client_id` or an
 * unregistered `redirect_uri` must NOT be redirected anywhere, because the
 * address cannot be trusted — those come back as errors for the screen to
 * display. Everything else is a redirect, error included.
 */
class AuthorizeController extends Controller
{
    public function __construct(
        private AuthorizationService $authorization,
        private ClientRegistrar $registrar,
    ) {}

    /** What the person is being asked to approve. */
    public function show(Request $request): JsonResponse
    {
        [$client, $params] = $this->resolve($request);

        $user = $request->user();

        return response()->json([
            'data' => [
                'client' => [
                    'name' => $client->client_name,
                    'uri' => $client->client_uri,
                    // How the client was registered. "Registered itself" is
                    // worth showing: it means nobody vetted the name on this
                    // screen, and the name is the only thing the person has.
                    'verified' => $client->source === McpClient::SOURCE_CIMD,
                ],
                'scopes' => $params['scopes'],
                'workspace' => [
                    'name' => $user->tenant?->name,
                ],
                'account' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    /** The person answered. */
    public function store(Request $request): JsonResponse
    {
        [$client, $params] = $this->resolve($request);

        $approved = $request->boolean('approve');

        if (! $approved) {
            return response()->json([
                'data' => ['redirect_to' => $this->authorization->denial($params['redirect_uri'], $params['state'])],
            ]);
        }

        try {
            $redirect = $this->authorization->issueCode(
                $request->user(),
                $client,
                $params['scopes'],
                $params['redirect_uri'],
                $params['code_challenge'],
                $params['resource'],
                $params['state'],
            );
        } catch (OAuthException $e) {
            // A refusal the person can act on (too many connections), so it is
            // shown on the screen rather than bounced through the redirect,
            // where the editor would render it as a bare error code.
            return response()->json(['message' => $e->getMessage(), 'code' => 'mcp_'.$e->error], 422);
        }

        return response()->json(['data' => ['redirect_to' => $redirect]]);
    }

    /**
     * @return array{0: McpClient, 1: array<string, mixed>}
     */
    private function resolve(Request $request): array
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:512'],
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'response_type' => ['nullable', 'string'],
            'scope' => ['nullable', 'string', 'max:512'],
            'state' => ['nullable', 'string', 'max:512'],
            'code_challenge' => ['required', 'string', 'min:43', 'max:128'],
            'code_challenge_method' => ['required', 'string'],
            'resource' => ['nullable', 'string', 'max:512'],
        ]);

        if (($validated['response_type'] ?? 'code') !== 'code') {
            $this->refuse('Only the authorization code flow is supported.', 'mcp_bad_response_type');
        }

        if ($validated['code_challenge_method'] !== 'S256') {
            $this->refuse('This server requires PKCE with S256.', 'mcp_bad_challenge_method');
        }

        if (! McpUrls::isOwnResource($validated['resource'] ?? null)) {
            $this->refuse('The app asked for access to a different server.', 'mcp_bad_resource');
        }

        try {
            $client = $this->registrar->resolve($validated['client_id']);
        } catch (ClientRegistrationException $e) {
            $this->refuse($e->getMessage(), 'mcp_unknown_client');
        }

        if (! $client) {
            $this->refuse('This app is not registered with Pingly.', 'mcp_unknown_client');
        }

        // ⚠️ Never redirect to an address the client was not registered with.
        // Everything else on this screen can be reported through the redirect;
        // this cannot, because the redirect is the thing in question.
        if (! $client->allowsRedirect($validated['redirect_uri'])) {
            $this->refuse('This app asked to be sent somewhere it is not registered for.', 'mcp_bad_redirect');
        }

        return [$client, [
            'redirect_uri' => $validated['redirect_uri'],
            'scopes' => Scopes::parse($validated['scope'] ?? null),
            'state' => $validated['state'] ?? null,
            'code_challenge' => $validated['code_challenge'],
            'resource' => $validated['resource'] ?? null,
        ]];
    }

    private function refuse(string $message, string $code): never
    {
        abort(response()->json(['message' => $message, 'code' => $code], 422));
    }
}
