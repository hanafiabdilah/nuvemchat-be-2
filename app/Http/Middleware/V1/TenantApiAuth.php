<?php

namespace App\Http\Middleware\V1;

use App\Models\Connection;
use App\Models\TenantApiKey;
use App\Services\Billing\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the workspace-level public API.
 *
 * Deliberately not V1\Auth, which accepts connection keys: a connection key
 * speaks for one number, and these endpoints open conversations on any of the
 * workspace's numbers and write to its sales board.
 *
 * Accepts the key as `X-Api-Key` (what the existing endpoint documents) or as a
 * bearer token (what most HTTP clients and no-code tools offer first).
 */
class TenantApiAuth
{
    public function __construct(
        private SubscriptionGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = trim((string) ($request->header('X-Api-Key') ?: $request->bearerToken()));

        if ($plain === '') {
            return $this->refuse('Envie a chave de API da conta no cabeçalho X-Api-Key.', 'api_key_missing');
        }

        $key = TenantApiKey::findActive($plain);

        if (! $key) {
            // The likeliest mistake on the Developer page, which lists both
            // kinds of key: say which one this is rather than just "invalid".
            if (! str_starts_with($plain, TenantApiKey::PREFIX) && Connection::where('api_key', $plain)->exists()) {
                return $this->refuse(
                    'Esta é a chave de uma conexão. Este endpoint precisa de uma chave da conta (Desenvolvedor › Chaves da conta).',
                    'connection_key_not_accepted',
                );
            }

            return $this->refuse('Chave de API inválida ou revogada.', 'api_key_invalid');
        }

        $tenant = $key->tenant;

        if (! $tenant) {
            return $this->refuse('Chave de API inválida ou revogada.', 'api_key_invalid');
        }

        // Same rule as EnsureSubscriptionActive, master switch included: a
        // workspace locked out of its dashboard cannot keep filling its inbox
        // from outside.
        if (config('services.billing.enforce') && ! $this->gate->usable($tenant)) {
            return response()->json([
                'message' => 'A assinatura desta conta está suspensa. Regularize o pagamento no Pingly para voltar a usar a API.',
                'code' => 'subscription_suspended',
            ], 403);
        }

        $key->recordUse($request->ip());

        $request->attributes->set('tenant_api_key', $key);

        return $next($request);
    }

    private function refuse(string $message, string $code): Response
    {
        return response()->json(['message' => $message, 'code' => $code], 401);
    }
}
