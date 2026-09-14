<?php

namespace App\Http\Middleware\V1;

use App\Models\ApiKey;
use App\Services\Billing\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the public API (/api/v1/*) with the workspace's API key.
 *
 * The key says which workspace is calling; each request names the connection
 * it acts on (`connection_id`), so one key serves every number the workspace
 * has. Accepted as `X-Api-Key` or as a bearer token (what most HTTP clients and
 * no-code tools offer first).
 */
class ApiKeyAuth
{
    public function __construct(
        private SubscriptionGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = trim((string) ($request->header('X-Api-Key') ?: $request->bearerToken()));

        if ($plain === '') {
            return $this->refuse('Envie a chave de API no cabeçalho X-Api-Key.', 'api_key_missing');
        }

        $key = ApiKey::findActive($plain);
        $tenant = $key?->tenant;

        if (! $key || ! $tenant) {
            return $this->refuse('Chave de API inválida ou revogada.', 'api_key_invalid');
        }

        // Same rule as EnsureSubscriptionActive, master switch included: a
        // workspace locked out of its dashboard cannot keep using it from outside.
        if (config('services.billing.enforce') && ! $this->gate->usable($tenant)) {
            return response()->json([
                'message' => 'A assinatura desta conta está suspensa. Regularize o pagamento no Pingly para voltar a usar a API.',
                'code' => 'subscription_suspended',
            ], 403);
        }

        $key->recordUse($request->ip());

        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    private function refuse(string $message, string $code): Response
    {
        return response()->json(['message' => $message, 'code' => $code], 401);
    }
}
