<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubProviderCredentialResource;
use App\Models\AiHubProviderCredential;
use App\Services\AiCredits\AiCreditPricing;
use App\Services\AiCredits\AiCreditService;
use App\Services\AiCredits\AiTokenPool;
use App\Services\AiCredits\AiTokenRentalService;
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
        private readonly AiCreditService $credits,
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

        return response()->json([
            'enabled' => (bool) config('ai.credits.enabled', true),
            'available_providers' => $this->pool->availableProviders(),
            'rentals' => AiHubProviderCredentialResource::collection($this->rentals->rentals($tenant)),
            'balance_cents' => $this->credits->balanceCents($tenant),
            // The published price of each model, repeated here rather than left
            // to /ai-credits: choosing a model happens in the agent form, which
            // is behind the agent permissions, and somebody without
            // `billing.view` still has to be able to see what they are about to
            // commit their workspace to spending.
            'models' => AiCreditPricing::priceList(),
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
