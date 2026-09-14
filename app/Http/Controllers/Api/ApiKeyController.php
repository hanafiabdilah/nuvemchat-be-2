<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API keys, from the dashboard (Developer › API keys).
 *
 * No update: a key's only property worth changing is the secret, and changing
 * a secret is revoke + create — which also forces whoever does it to notice
 * which integration they are about to break.
 */
class ApiKeyController extends Controller
{
    public function index(Request $request)
    {
        $keys = ApiKey::active()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with('creator:id,name')
            ->latest('id')
            ->get();

        return ApiKeyResource::collection($keys);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $active = ApiKey::active()->where('tenant_id', $user->tenant_id)->count();

        if ($active >= ApiKey::MAX_ACTIVE_PER_TENANT) {
            throw new PublicApiException(
                'Esta conta já tem '.ApiKey::MAX_ACTIVE_PER_TENANT.' chaves ativas. Revogue uma que não é mais usada para criar outra.',
                'api_key_limit',
            );
        }

        [$key, $plain] = ApiKey::issue($user->tenant, trim($data['name']), $user);

        Log::info('API key created', [
            'tenant_id' => $user->tenant_id,
            'api_key_id' => $key->id,
            'actor_id' => $user->id,
        ]);

        return (new ApiKeyResource($key->load('creator:id,name')))
            ->additional(['plain_key' => $plain])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $key = ApiKey::active()->where('tenant_id', $user->tenant_id)->findOrFail($id);

        $key->forceFill(['revoked_at' => now()])->save();

        Log::info('API key revoked', [
            'tenant_id' => $user->tenant_id,
            'api_key_id' => $key->id,
            'actor_id' => $user->id,
        ]);

        return response()->json(['message' => 'API key revoked']);
    }
}
