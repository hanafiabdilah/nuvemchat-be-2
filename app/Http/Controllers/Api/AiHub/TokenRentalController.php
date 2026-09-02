<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubProviderCredentialResource;
use App\Models\AiHubProviderCredential;
use App\Services\Credits\CreditPricing;
use App\Services\Credits\CreditService;
use App\Services\AiTokens\AiTokenPool;
use App\Services\AiTokens\AiTokenRentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Renting a platform-owned provider key instead of pasting one of your own.
 *
 * What the workspace gets back is an ordinary provider credential, which is
 * what makes the rest of the AI surface work unchanged — the agent form, the
 * trained-agent fork and the flow node's audio settings all just see one more
 * entry in the dropdown.
 */
class TokenRentalController extends Controller
{
    public function __construct(
        private readonly AiTokenRentalService $rentals,
        private readonly AiTokenPool $pool,
        private readonly CreditService $credits,
    ) {}

    /**
     * What can be rented, what already is, and what it will be spent from.
     *
     * All three in one response because none of them means anything alone: a
     * list of rentable providers is an invitation to spend a balance the page
     * would otherwise have to fetch separately to show.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $rentals = $this->rentals->rentals($tenant);
        $providers = $this->pool->availableProviders();

        // Providers the workspace could start renting today, plus the ones it
        // already rents — a pool key that has since filled up must not make an
        // agent's current model vanish from the form that edits it.
        $offerable = array_values(array_unique(array_merge(
            $providers,
            $rentals->pluck('provider')->map(fn ($p) => strtoupper($p))->all(),
        )));

        return response()->json([
            'enabled' => (bool) config('ai.credits.enabled', true),
            'available_providers' => $providers,
            'rentals' => AiHubProviderCredentialResource::collection($rentals),
            'balance_cents' => $this->credits->balanceCents($tenant),
            // Every model these providers can run, with a price on the ones
            // that have a published figure. This is the whole basis of the
            // choice, so it travels with the offer rather than living on the
            // billing page: the person building an agent decides here, and may
            // not even hold `billing.view`.
            //
            // Filtered to providers that can actually be rented — offering a
            // model with no key behind it is an offer we cannot honour.
            'models' => CreditPricing::rentableModels($offerable),
        ]);
    }

    /**
     * Rent a key for one provider.
     *
     * Idempotent: a workspace that already rents this provider gets the
     * credential it has, so a double click is not a second key.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
        ]);

        $tenant = $request->user()->tenant;

        try {
            $credential = $this->rentals->rent($tenant, $validated['provider']);
        } catch (\RuntimeException $e) {
            // The pool being empty is a stock problem, not a bug: a 422 the
            // screen can print beats a 500 nobody can act on.
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Token rented',
            'data' => new AiHubProviderCredentialResource($credential),
        ], 201);
    }

    /**
     * Give a rented key back. Refused while an agent or a flow node still
     * points at it — see AiTokenRentalService::release().
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $credential = AiHubProviderCredential::query()
            ->rented()
            ->whereHas('aiHubTenant', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->findOrFail($id);

        $this->rentals->release($credential);

        return response()->json(['message' => 'Token returned']);
    }
}
