<?php

namespace App\Http\Middleware\Mcp;

use App\Enums\Billing\Feature;
use App\Models\McpConnection;
use App\Models\McpToken;
use App\Services\Billing\SubscriptionGate;
use App\Services\Mcp\McpUrls;
use App\Services\Mcp\Scopes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an MCP access token back into the person who approved it.
 *
 * ⚠️ `Auth::setUser()` at the end is load-bearing, not tidiness. Everything
 * downstream — `FlowBlueprint::rulesFor()`, which scopes half its `exists`
 * rules with `auth()->user()->tenant_id`, and every `$user->can()` in a tool —
 * reads the authenticated user from the container. Without it the tenant id is
 * 0, every tenant-scoped rule matches nothing, and the failure arrives as
 * "that tag does not exist" rather than as anything about authentication.
 *
 * Deliberately NOT `auth:sanctum`. An MCP token is not a Sanctum token and must
 * never be accepted as one: nothing under /api checks token abilities, so a
 * Sanctum token minted for this surface would be a full dashboard credential.
 */
class AuthenticateMcp
{
    public function __construct(
        private SubscriptionGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presented = trim((string) $request->bearerToken());

        if ($presented === '') {
            return $this->challenge('Authentication is required.');
        }

        $token = McpToken::with(['connection.user', 'connection.tenant'])
            ->where('token_hash', McpToken::hash($presented))
            ->where('type', McpToken::TYPE_ACCESS)
            ->first();

        if (! $token || ! $token->isUsable()) {
            return $this->challenge('This token has expired or is not valid.');
        }

        $connection = $token->connection;

        if (! $connection || ! $connection->isActive()) {
            return $this->challenge('This connection has been disconnected.');
        }

        $user = $connection->user;
        $tenant = $connection->tenant;

        // The account was removed, or moved out of the workspace it was
        // connected to. The grant was to a person in a workspace; neither half
        // survives on its own.
        if (! $user || ! $tenant || $user->tenant_id !== $tenant->id) {
            return $this->challenge('The account behind this connection is no longer available.');
        }

        // Same rule as EnsureSubscriptionActive and the public API's key auth,
        // master switch included: a workspace locked out of its dashboard does
        // not keep working from an editor.
        if (config('services.billing.enforce')) {
            if (! $this->gate->usable($tenant)) {
                return $this->forbidden($connection, 'subscription_suspended', 'This workspace\'s subscription is suspended.');
            }

            if (! $this->gate->feature($tenant, Feature::Mcp->value)) {
                return $this->forbidden($connection, 'feature_not_in_plan', 'This workspace\'s plan does not include MCP access.');
            }
        }

        $connection->recordUse($request->ip());

        $request->attributes->set('mcp_connection', $connection);

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * RFC 9728 §5.1: the challenge carries the address of the protected-resource
     * metadata, which is how a client that has never seen this server finds the
     * authorization server without being configured with it.
     */
    private function challenge(string $description): Response
    {
        return response()->json([
            'error' => 'invalid_token',
            'error_description' => $description,
        ], 401)->header('WWW-Authenticate', sprintf(
            'Bearer resource_metadata="%s", scope="%s", error="invalid_token", error_description="%s"',
            McpUrls::protectedResourceMetadata(),
            Scopes::toHeader(Scopes::all()),
            $description,
        ));
    }

    /**
     * ⚠️ Logged, unlike the 401s above.
     *
     * A 401 is the normal opening move of every MCP client — it is how they
     * discover where to authorize — so logging those would be noise. A 403 here
     * is the opposite: the caller holds a token this server issued, and is
     * being turned away for a reason on our side of the account. That happened
     * in production on the first day, silently, and the editor reported it to
     * the person as a problem with their credentials.
     */
    private function forbidden(McpConnection $connection, string $code, string $description): Response
    {
        Log::warning('MCP: refused a valid token', [
            'code' => $code,
            'tenant_id' => $connection->tenant_id,
            'mcp_connection_id' => $connection->id,
            'client_name' => $connection->client_name,
        ]);

        return response()->json([
            'error' => 'access_denied',
            'error_description' => $description,
            'code' => $code,
        ], 403);
    }
}
