<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AiToken\TokenPoolKeyStatus;
use App\Http\Controllers\Controller;
use App\Models\AiHubRun;
use App\Models\AiTokenPoolKey;
use App\Models\AuditLog;
use App\Services\AiTokens\AiTokenRentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Back Office: the pool of provider keys the platform rents out.
 *
 * Two rules run through the whole controller. The raw key is write-only — it
 * goes in and is never read back, not even by the admin who typed it, because
 * an endpoint that can return it is an endpoint that can leak it and the real
 * copy lives at the provider anyway. And every mutation is audited: this is the
 * one screen where a wrong click stops AI for every workspace sharing a key.
 */
class AdminAiTokenPoolController extends Controller
{
    public function __construct(
        private readonly AiTokenRentalService $rentals,
    ) {}

    /**
     * Every key, with the two numbers that make the list worth looking at:
     * how many workspaces are on it, and what has been run through it.
     *
     * Spend is joined via the credentials minted from the key, which is the
     * only link between a run and the key that paid for it — `ai_hub_runs`
     * records the agent, and the agent records the credential.
     */
    public function index(): JsonResponse
    {
        $keys = AiTokenPoolKey::query()
            ->withCount('credentials')
            ->orderBy('provider')
            ->orderBy('label')
            ->get();

        $spend = $this->spendByPoolKey($keys->pluck('id')->all());

        return response()->json([
            'data' => $keys->map(fn (AiTokenPoolKey $key) => [
                'id' => $key->id,
                'provider' => $key->provider,
                'label' => $key->label,
                'key_preview' => $key->key_preview,
                'default_model' => $key->default_model,
                'status' => $key->status->value,
                'weight' => $key->weight,
                'max_tenants' => $key->max_tenants,
                'tenants' => $key->credentials_count,
                // Null max_tenants is unlimited, so "full" is only meaningful
                // when a cap was actually set.
                'is_full' => $key->max_tenants !== null && $key->credentials_count >= $key->max_tenants,
                'runs' => (int) ($spend[$key->id]->runs ?? 0),
                'cost_usd' => round((float) ($spend[$key->id]->cost_usd ?? 0), 6),
                'meta' => $key->meta,
                'created_at' => $key->created_at,
            ])->values(),
            'statuses' => TokenPoolKeyStatus::values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'label' => ['required', 'string', 'max:120'],
            'api_key' => ['required', 'string', 'max:500'],
            'default_model' => ['nullable', 'string', 'max:100'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_tenants' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'meta' => ['nullable', 'array'],
        ]);

        $key = AiTokenPoolKey::create([
            'provider' => strtoupper($validated['provider']),
            'label' => $validated['label'],
            'api_key' => $validated['api_key'],
            'key_preview' => AiTokenPoolKey::previewFor($validated['api_key']),
            'default_model' => $validated['default_model'] ?? null,
            'status' => TokenPoolKeyStatus::Active,
            'weight' => $validated['weight'] ?? 1,
            'max_tenants' => $validated['max_tenants'] ?? null,
            'meta' => $validated['meta'] ?? null,
        ]);

        $this->audit($request, 'ai_token_pool.created', $key, ['provider' => $key->provider, 'label' => $key->label]);

        return response()->json(['message' => 'Token added', 'data' => ['id' => $key->id]], 201);
    }

    /**
     * Edit a key.
     *
     * Revoking is the one change with a consequence beyond this row: the secret
     * is gone, so every workspace holding it is already broken and has to be
     * moved onto another key. That runs inline rather than in a job because the
     * admin needs to know, in the response, whether the pool had spares — a
     * rotation that silently failed for want of a second key would leave those
     * workspaces exactly as broken as doing nothing.
     */
    public function update(Request $request, AiTokenPoolKey $key): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:' . implode(',', TokenPoolKeyStatus::values())],
            'weight' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'max_tenants' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        $attributes = collect($validated)->except('api_key')->all();

        if (! empty($validated['api_key'])) {
            // Replacing the secret in place does NOT reach the workspaces
            // already holding it: the hub stored a copy of the old one when
            // each credential was minted. Rotating them onto the new value is
            // what `status = revoked` is for, and saying so here is cheaper
            // than an admin discovering it from a support ticket.
            $attributes['api_key'] = $validated['api_key'];
            $attributes['key_preview'] = AiTokenPoolKey::previewFor($validated['api_key']);
        }

        $key->update($attributes);

        $rotation = null;

        if ($key->fresh()->status->requiresRotation()) {
            $rotation = $this->rentals->rotateAllFrom($key->fresh());
        }

        $this->audit($request, 'ai_token_pool.updated', $key, [
            'changed' => array_keys($attributes),
            'rotation' => $rotation,
        ]);

        return response()->json([
            'message' => 'Token updated',
            'rotation' => $rotation,
        ]);
    }

    /**
     * Delete a key.
     *
     * Refused while workspaces are still on it. The foreign key is
     * `nullOnDelete`, so deleting would leave their credentials looking like
     * the tenant's own — still working, still spending the platform's money,
     * and no longer billed. Revoke first, which moves them.
     */
    public function destroy(Request $request, AiTokenPoolKey $key): JsonResponse
    {
        $inUse = $key->credentials()->count();

        abort_if(
            $inUse > 0,
            422,
            "Still rented by {$inUse} workspace(s). Set the status to revoked first — that moves them onto another key."
        );

        $this->audit($request, 'ai_token_pool.deleted', $key, ['provider' => $key->provider, 'label' => $key->label]);

        $key->delete();

        return response()->json(['message' => 'Token removed']);
    }

    /**
     * Runs and provider cost per pool key.
     *
     * Joined through the credentials minted from the key, because that is the
     * only link a run has to the key that paid for it: `ai_hub_runs` records the
     * agent, and the agent records the credential.
     *
     * ⚠️ Approximate across a rotation. When a workspace is moved to another
     * key its agent is re-pointed, so its past runs follow it and are counted
     * against the new key. Fine for what this column is for — spotting which
     * key is being hammered — and deliberately not the money record: that is
     * `credit_transactions`, which prices each run when it happens and never
     * moves afterwards.
     *
     * @param  list<int>  $keyIds
     */
    private function spendByPoolKey(array $keyIds)
    {
        if ($keyIds === []) {
            return collect();
        }

        return AiHubRun::query()
            ->join('ai_hub_agents', 'ai_hub_agents.id', '=', 'ai_hub_runs.ai_hub_agent_id')
            ->join('ai_hub_provider_credentials', 'ai_hub_provider_credentials.id', '=', 'ai_hub_agents.ai_hub_provider_credential_id')
            ->whereIn('ai_hub_provider_credentials.ai_token_pool_key_id', $keyIds)
            ->groupBy('ai_hub_provider_credentials.ai_token_pool_key_id')
            ->select([
                'ai_hub_provider_credentials.ai_token_pool_key_id as pool_key_id',
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(COALESCE(ai_hub_runs.cost_usd, 0)) as cost_usd'),
            ])
            ->get()
            ->keyBy('pool_key_id');
    }

    private function audit(Request $request, string $action, AiTokenPoolKey $key, array $meta = []): void
    {
        AuditLog::record(
            $action,
            "{$key->provider} — {$key->label} (#{$key->id})",
            $meta + ['ai_token_pool_key_id' => $key->id],
            $request->user(),
        );
    }
}
