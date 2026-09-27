<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\OAuth\AuthorizationService;
use App\Services\Mcp\OAuth\ClientRegistrar;
use App\Services\Mcp\OAuth\OAuthException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The token endpoint, and its revocation twin.
 *
 * No client authentication: every client here is public, and PKCE is what
 * proves the caller presenting a code is the one that started the exchange.
 */
class TokenController extends Controller
{
    public function __construct(
        private AuthorizationService $authorization,
        private ClientRegistrar $registrar,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();
        $grant = (string) ($input['grant_type'] ?? '');

        try {
            $tokens = match ($grant) {
                'authorization_code' => $this->authorization->exchange($input, $this->registrar),
                'refresh_token' => $this->authorization->refresh($input, $this->registrar),
                default => throw OAuthException::unsupportedGrantType(
                    "This server issues tokens for authorization_code and refresh_token, not {$grant}."
                ),
            };
        } catch (OAuthException $e) {
            return response()->json($e->payload(), $e->status)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($tokens)->header('Cache-Control', 'no-store');
    }

    /**
     * RFC 7009. Always 200, even for a token that never existed — the endpoint
     * would otherwise answer "was this ever a real token", which is a question
     * an unauthenticated caller should not be able to ask.
     */
    public function revoke(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('token', ''));

        if ($token !== '') {
            $this->authorization->revoke($token);
        }

        return response()->json(['revoked' => true]);
    }
}
