<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\SavedCardResource;
use App\Models\Tenant;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\SavedCardService;
use Illuminate\Http\Request;

/**
 * The workspace's saved cards (Brazil, Mercado Pago).
 *
 * `available` is what the checkout and the top-up branch on: false wherever the
 * gateway that bills this workspace keeps no cards, and then the list is empty
 * rather than an error.
 */
class SavedCardController extends Controller
{
    public function __construct(protected SavedCardService $cards) {}

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $available = $this->cards->gatewayFor($tenant) !== null;

        return response()->json([
            'data' => $available ? SavedCardResource::collection($this->cards->list($tenant)) : [],
            'available' => $available,
            // What the card fields need to mint a token in the browser.
            'public_key' => $available ? DirectBillingConfig::mpPublicKey() : null,
            // Mercado Pago asks for the cardholder's CPF/CNPJ when a new card is
            // tokenised; the workspace's billing document is the sensible default.
            'identification' => in_array($tenant->billing_document_type, ['CPF', 'CNPJ'], true)
                ? ['type' => $tenant->billing_document_type, 'number' => $tenant->billing_document_number]
                : null,
            'max_cards' => SavedCardService::MAX_CARDS,
        ]);
    }

    /** Keep a card from a single-use token (the checkout does this before charging it). */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_token' => ['required', 'string', 'max:128'],
        ]);

        $card = $this->cards->add($this->tenant($request), $validated['card_token'], $request->user());

        return response()->json(['data' => new SavedCardResource($card)], 201);
    }

    public function destroy(Request $request, int $card)
    {
        $tenant = $this->tenant($request);
        $found = $this->cards->list($tenant)->firstWhere('id', $card);

        abort_if($found === null, 404);

        $this->cards->remove($found);

        return response()->json(['message' => 'Card removed']);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->user()->tenant;
    }
}
